<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Container\Container;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Exception\BeelNotReadyError;
use Lenorix\BeelSdk\Generated\Model\CreateInvoiceExportRequest;
use Lenorix\BeelSdk\Generated\Model\CreateInvoicePdfArchiveRequest;
use Lenorix\BeelSdk\Resource\Company\CompanyInvoicesResource;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\Support\DocumentKind;
use Lenorix\LaravelBeel\Support\SignedDownloadStorage;

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
     * The PDF is streamed from BeeL's pre-signed URL into the disk in `beel.downloads.buffer_bytes` steps
     * (64 KiB), never loaded whole: on local, FTP and SFTP disks memory stays at about that, whatever
     * the PDF's size. Other adapters add their own bounded buffer: the S3 one keeps an upload of
     * unknown size in `php://temp` (up to 2 MB in memory, then disk), so a small PDF sits there whole.
     * Streaming needs `allow_url_fopen`; without it Guzzle falls back to cURL, which buffers the body
     * in `php://temp` (bounded memory, spills to disk). Proxy and TLS settings from
     * `beel.http.options` apply to the download too.
     *
     * It writes a temporary file next to the target and moves it into place only once the download
     * is complete: it must start with the PDF signature (`%PDF-`) and match the declared length and the stored
     * size. So a failed attempt never leaves a partial file, and with `overwrite: true` never touches
     * the existing one. Connection errors, 5xx, an expired URL (403) and failed checks are retried
     * with a new URL, up to `beel.downloads.attempts`.
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
     * @throws DocumentAlreadyExists The path exists and `$overwrite` is false.
     * @throws InvoicePdfNotReady BeeL is still generating the PDF; try again shortly.
     * @throws DocumentDownloadFailed Every attempt failed; nothing was written to `$path`.
     * @throws BeelApiError From BeeL, e.g. a draft has no PDF
     *                      (`INVOICE_NOT_ISSUED_NO_PDF`).
     */
    public function storePdf(string $invoiceId, string $path, ?string $disk = null, bool $overwrite = false, array $options = []): string
    {
        return $this->storage()->store(
            function () use ($invoiceId): string {
                try {
                    return $this->resource->getPdf($invoiceId)->getDownloadUrl();
                } catch (BeelNotReadyError $notReady) {
                    throw new InvoicePdfNotReady($invoiceId, $notReady->retryAfter, $notReady);
                }
            },
            DocumentKind::Pdf, "the PDF of invoice {$invoiceId}", $path, $disk, $overwrite, $options,
        );
    }

    /**
     * Store an invoice's preview image (WebP, drafts included) on a Laravel disk and return the path,
     * with the same streaming, verification and atomic write as storePdf().
     *
     * @param  array<string, mixed>  $options  Passed to the disk; `ContentType` defaults to `image/webp`.
     *
     * @throws DocumentAlreadyExists The path exists and `$overwrite` is false.
     * @throws DocumentDownloadFailed Every attempt failed; nothing was written to `$path`.
     */
    public function storePreview(string $invoiceId, string $path, ?string $disk = null, bool $overwrite = false, array $options = []): string
    {
        return $this->storage()->store(
            fn (): string => $this->resource->preview($invoiceId)->getImageUrl(),
            DocumentKind::Webp, "the preview of invoice {$invoiceId}", $path, $disk, $overwrite, $options,
        );
    }

    /**
     * Store a ZIP with the PDFs of up to 500 invoices on a Laravel disk. Invoices without an
     * available PDF are left out: check `counts` (`total`, `successful`, `failed`) on the result.
     *
     * The ZIP comes in BeeL's response and is streamed into the disk, verified and written
     * atomically like storePdf(). It is never re-requested after a failure, since each request makes
     * BeeL build the archive again.
     *
     * @param  CreateInvoicePdfArchiveRequest|array<string, mixed>  $request  e.g. `['invoice_ids' => [...]]`.
     * @param  array<string, mixed>  $options  Passed to the disk; `ContentType` defaults to BeeL's.
     *
     * @throws DocumentAlreadyExists The path exists and `$overwrite` is false; nothing was requested.
     * @throws DocumentDownloadFailed Storing failed; nothing was written to `$path`.
     * @throws BeelApiError From BeeL, e.g. when no PDF is available.
     */
    public function storePdfArchive(CreateInvoicePdfArchiveRequest|array $request, string $path, ?string $disk = null, bool $overwrite = false, array $options = []): StoredDocument
    {
        return $this->storage()->storeDownload(
            fn () => $this->resource->createPdfArchive($request),
            DocumentKind::Zip, 'the invoice PDF archive', $path, $disk, $overwrite, $options,
        );
    }

    /**
     * Store a spreadsheet (`.xlsx`) export of up to 50,000 invoices on a Laravel disk, streamed,
     * verified and written atomically, and never re-requested after a failure. `counts['total']` is
     * the number of invoices exported.
     *
     * @param  CreateInvoiceExportRequest|array<string, mixed>  $request  `invoice_ids` or `filters`, and `format`.
     * @param  array<string, mixed>  $options  Passed to the disk; `ContentType` defaults to BeeL's.
     *
     * @throws DocumentAlreadyExists The path exists and `$overwrite` is false; nothing was requested.
     * @throws DocumentDownloadFailed Storing failed; nothing was written to `$path`.
     * @throws BeelApiError From BeeL, e.g. `EXPORT_SELECTION_REQUIRED` or `EXPORT_LIMIT_EXCEEDED`.
     */
    public function storeExport(CreateInvoiceExportRequest|array $request, string $path, ?string $disk = null, bool $overwrite = false, array $options = []): StoredDocument
    {
        return $this->storage()->storeDownload(
            fn () => $this->resource->export($request),
            DocumentKind::Zip, 'the invoice export', $path, $disk, $overwrite, $options,
        );
    }

    /**
     * Store the PDF preview of an invoice (drafts included; it has no fiscal validity) on a Laravel
     * disk, streamed, verified and written atomically.
     *
     * @param  array<string, mixed>  $options  Passed to the disk; `ContentType` defaults to BeeL's.
     *
     * @throws DocumentAlreadyExists The path exists and `$overwrite` is false; nothing was requested.
     * @throws DocumentDownloadFailed Storing failed; nothing was written to `$path`.
     */
    public function storePreviewPdf(string $invoiceId, string $path, ?string $disk = null, bool $overwrite = false, array $options = []): StoredDocument
    {
        return $this->storage()->storeDownload(
            fn () => $this->resource->previewPdf($invoiceId),
            DocumentKind::Pdf, "the PDF preview of invoice {$invoiceId}", $path, $disk, $overwrite, $options,
        );
    }

    private function storage(): SignedDownloadStorage
    {
        return Container::getInstance()->make(SignedDownloadStorage::class);
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
