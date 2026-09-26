<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Container\Container;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Resource\Company\CompanyInvoicesResource;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfDownloadFailed;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\Support\InvoicePdfStorage;

/**
 * The SDK's company invoices resource plus storePdf(). Every other method and property is the
 * SDK's; withOptions() keeps returning this decorator.
 *
 * @mixin CompanyInvoicesResource
 *
 * @method self withOptions(\Lenorix\BeelSdk\Http\RequestOptions $options) Per-call options; keeps storePdf().
 */
final class BeelCompanyInvoices
{
    public function __construct(public readonly CompanyInvoicesResource $resource) {}

    /**
     * Store an issued invoice's PDF on a Laravel disk (local, S3, FTP, SFTP, ...) and return the path.
     *
     * The PDF is streamed from BeeL's pre-signed URL into the disk in `beel.pdf.buffer_bytes` steps
     * (64 KiB), never loaded whole: on local, FTP and SFTP disks memory stays at about that, whatever
     * the PDF's size. Other adapters add their own bounded buffer: the S3 one keeps an upload of
     * unknown size in `php://temp` (up to 2 MB in memory, then disk), so a small PDF sits there whole.
     * Streaming needs `allow_url_fopen`; without it Guzzle falls back to cURL, which buffers the body
     * in `php://temp` (bounded memory, spills to disk). Proxy and TLS settings from
     * `beel.http.options` apply to the download too.
     *
     * It writes a temporary file next to the target and moves it into place only once the download
     * is complete: it must start with the PDF signature and match the declared length and the stored
     * size. So a failed attempt never leaves a partial file, and with `overwrite: true` never touches
     * the existing one. Connection errors, 5xx, an expired URL (403) and failed checks are retried
     * with a new URL, up to `beel.pdf.attempts`.
     *
     * The existence check (without `overwrite`) runs before calling BeeL and again before the move;
     * a concurrent writer between that last check and the move isn't prevented. A process killed
     * mid-download (e.g. a queue worker hitting its timeout) skips the cleanup and can leave a
     * `.beel-{random}-{name}` file next to the target, safe to delete.
     *
     * @param  string|null  $disk  Disk name; null uses the default disk.
     * @param  array<string, mixed>  $options  Passed to the disk (e.g. `visibility`); `ContentType`
     *                                         defaults to `application/pdf`.
     *
     * @throws InvoicePdfAlreadyExists The path exists and `$overwrite` is false.
     * @throws InvoicePdfNotReady BeeL is still generating the PDF; try again shortly.
     * @throws InvoicePdfDownloadFailed Every attempt failed; nothing was written to `$path`.
     * @throws BeelApiError From BeeL, e.g. a draft has no PDF
     *                      (`INVOICE_NOT_ISSUED_NO_PDF`).
     */
    public function storePdf(string $invoiceId, string $path, ?string $disk = null, bool $overwrite = false, array $options = []): string
    {
        return Container::getInstance()->make(InvoicePdfStorage::class)
            ->store($this->resource, $invoiceId, $path, $disk, $overwrite, $options);
    }

    public function __get(string $name): mixed
    {
        return $this->resource->{$name};
    }

    /** @param array<int|string, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->resource->{$name}(...$arguments);

        return $result instanceof CompanyInvoicesResource ? new self($result) : $result;
    }
}
