<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Exception\BeelRateLimitError;
use Lenorix\BeelSdk\Http\RequestOptions;
use Lenorix\LaravelBeel\BeelCompany;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\Jobs\Middleware\ThrottleBeelRequests;

/**
 * Stores a BeeL document on a Laravel disk from the queue, with the matching store method
 * (StoreInvoicePdf, StoreInvoicePreview, StoreInvoicePreviewPdf, StoreInvoicePdfArchive,
 * StoreInvoiceExport, StoreRepresentationDocument):
 *
 * - It never waits in the worker: while BeeL is still generating a PDF, or when rate limited, it goes
 *   back to the queue for the `Retry-After` BeeL gives.
 * - An existing file without `overwrite` counts as done, so dispatching twice is harmless.
 * - A failed download is retried with backoff (up to 5 exceptions, within a day); an error that
 *   retrying can't fix (a draft has no PDF, an unknown invoice, a missing representation) fails it
 *   at once. Retrying an archive or an export makes BeeL build it again.
 * - It stays under BeeL's rate limit (ThrottleBeelRequests) instead of provoking 429s when many are
 *   dispatched at once.
 *
 * Credentials come from the CredentialsResolver when the job runs. A resolver bound to the request
 * or tenant returns null in a queue worker, so pass `companyId` and `apiKey` then. The payload is
 * encrypted, since it may hold the API key.
 */
abstract class StoreBeelDocument implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Releases (waiting for BeeL or for the rate limit) are not failures: only exceptions count. */
    public int $tries = 0;

    public int $maxExceptions = 5;

    /**
     * @param  string|null  $disk  Disk name; null uses the default disk.
     * @param  array<string, mixed>  $options  Passed to the disk (e.g. `visibility`).
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $disk = null,
        public readonly bool $overwrite = false,
        public readonly array $options = [],
        public readonly ?string $companyId = null,
        #[\SensitiveParameter] public readonly ?string $apiKey = null,
    ) {}

    /** Stores the document on $this->disk at $this->path through $company. */
    abstract protected function store(BeelCompany $company): void;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleBeelRequests($this->apiKey)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(BeelManager $manager): void
    {
        try {
            // No in-process retries: waiting out a 429 would block the worker; the queue waits instead.
            $this->store($manager->company(apiKey: $this->apiKey, companyId: $this->companyId)->withOptions(new RequestOptions(maxRetries: 0)));
        } catch (InvoicePdfNotReady $exception) {
            $this->release($exception->retryAfter ?? 5);
        } catch (BeelRateLimitError $exception) {
            $this->release($exception->retryAfterSeconds);
        } catch (DocumentAlreadyExists) {
            // Already stored (e.g. by an earlier dispatch): nothing left to do.
        } catch (BeelApiError $exception) {
            if (self::isPermanent($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private static function isPermanent(BeelApiError $exception): bool
    {
        return $exception->statusCode >= 400 && $exception->statusCode < 500
            && ! in_array($exception->statusCode, [408, 429], true);
    }
}
