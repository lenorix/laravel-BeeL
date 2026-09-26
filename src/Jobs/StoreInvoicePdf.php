<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;

/**
 * Stores an issued invoice's PDF on a disk from the queue, with `storePdf()`:
 *
 *     StoreInvoicePdf::dispatch($invoiceId, 'invoices/A-42.pdf', disk: 's3');
 *
 * - While BeeL is still generating the PDF, it goes back to the queue for the `Retry-After` BeeL gives.
 * - An existing file without `overwrite` counts as done, so dispatching twice is harmless.
 * - A failed download is retried with backoff; an error that retrying can't fix (a draft has no
 *   PDF, an unknown invoice) fails the job at once.
 *
 * Credentials come from the CredentialsResolver when the job runs. A resolver bound to the request
 * or tenant returns null in a queue worker, so pass `companyId` and `apiKey` then. The payload is
 * encrypted, since it may hold the API key.
 */
final class StoreInvoicePdf implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param  string|null  $disk  Disk name; null uses the default disk.
     * @param  array<string, mixed>  $options  Passed to the disk (e.g. `visibility`).
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $path,
        public readonly ?string $disk = null,
        public readonly bool $overwrite = false,
        public readonly array $options = [],
        public readonly ?string $companyId = null,
        #[\SensitiveParameter] public readonly ?string $apiKey = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(BeelManager $manager): void
    {
        try {
            $manager->company(apiKey: $this->apiKey, companyId: $this->companyId)
                ->invoices->storePdf($this->invoiceId, $this->path, $this->disk, $this->overwrite, $this->options);
        } catch (InvoicePdfNotReady $exception) {
            $this->release($exception->retryAfter ?? 5);
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
