<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToMoveFile;
use Lenorix\BeelSdk\Exception\BeelNotReadyError;
use Lenorix\BeelSdk\Resource\Company\CompanyInvoicesResource;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfDownloadFailed;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;

/**
 * Streams an invoice PDF from BeeL's pre-signed URL into a Laravel disk. See BeelCompanyInvoices::storePdf().
 *
 * @internal
 */
final class InvoicePdfStorage
{
    public function __construct(
        private HttpFactory $http,
        private FilesystemFactory $filesystems,
        private ConfigRepository $config,
    ) {}

    /** @param array<string, mixed> $options */
    public function store(CompanyInvoicesResource $invoices, string $invoiceId, string $path, ?string $disk, bool $overwrite, array $options): string
    {
        $filesystem = $this->driver($disk);

        // Before calling BeeL, so an existing file costs no API call and no download.
        if (! $overwrite && $filesystem->fileExists($path)) {
            throw new InvoicePdfAlreadyExists($invoiceId, $path);
        }

        $attempts = max(1, (int) $this->config->get('beel.pdf.attempts', 3));
        $options += ['ContentType' => 'application/pdf'];

        for ($attempt = 1; ; $attempt++) {
            // A fresh URL every attempt: they expire after five minutes.
            try {
                $pdf = $invoices->getPdf($invoiceId);
            } catch (BeelNotReadyError $notReady) {
                throw new InvoicePdfNotReady($invoiceId, $notReady->retryAfter, $notReady);
            }

            try {
                $this->downloadInto($filesystem, $pdf->getDownloadUrl(), $path, $overwrite, $options, $invoiceId);

                return $path;
            } catch (PdfDownloadFailure $failure) {
                if (! $failure->retryable || $attempt >= $attempts) {
                    throw new InvoicePdfDownloadFailed($invoiceId, $failure->getMessage(), $attempt);
                }

                Sleep::usleep(max(0, (int) $this->config->get('beel.http.retry_delay_ms', 100)) * 1000);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function downloadInto(FilesystemOperator $filesystem, string $url, string $path, bool $overwrite, array $options, string $invoiceId): void
    {
        // A plain request: the pre-signed URL carries its own authorization, so BeeL's API key must
        // never reach it, and a half-read stream can't be retried by middleware anyway.
        try {
            $response = $this->http->withOptions($this->transportOptions() + [
                'stream' => true,
                'read_timeout' => (float) $this->config->get('beel.pdf.read_timeout', 30),
                'connect_timeout' => (float) $this->config->get('beel.http.connect_timeout', 10),
                'timeout' => 0,
            ])->get($url);
        } catch (ConnectionException $exception) {
            throw new PdfDownloadFailure(self::redact($exception->getMessage(), $url), retryable: true);
        }

        $status = $response->status();
        if ($status !== 200) {
            $reason = "the download answered HTTP {$status}.";

            // 403 is an expired or rejected pre-signed URL: a new one may work.
            throw new PdfDownloadFailure($reason, retryable: in_array($status, [403, 408, 429], true) || $status >= 500);
        }

        // Guzzle drops Content-Length when it decodes a Content-Encoding, so a remaining one always
        // describes the bytes read here.
        $length = $response->header('Content-Length');
        $body = new VerifiedPdfStream($response->toPsrResponse()->getBody(), ctype_digit($length) ? (int) $length : null);

        // Same directory, so the final move is a rename on local disks; ends like the target so disks
        // that guess the MIME type from the extension (S3, GCS) keep treating it as a PDF.
        $directory = dirname($path);
        $temporary = ($directory === '.' ? '' : $directory.'/').'.beel-'.Str::random(16).'-'.basename($path);

        $resource = StreamWrapper::getResource($body);
        stream_set_chunk_size($resource, max(8192, (int) $this->config->get('beel.pdf.buffer_bytes', 65536)));

        try {
            $filesystem->writeStream($temporary, $resource, $options);
            $body->verify();

            $stored = $filesystem->fileSize($temporary);
            if ($stored !== $body->bytesRead()) {
                throw new \UnexpectedValueException("the disk stored {$stored} of {$body->bytesRead()} bytes.");
            }

            // Checked again right before replacing, for a file created during the download.
            if (! $overwrite && $filesystem->fileExists($path)) {
                throw new InvoicePdfAlreadyExists($invoiceId, $path);
            }

            $this->moveIntoPlace($filesystem, $temporary, $path, $overwrite);
        } catch (\Throwable $exception) {
            try {
                $filesystem->delete($temporary);
            } catch (\Throwable) {
                // Best effort: the original failure matters more than a leftover temporary file.
            }

            if ($exception instanceof InvoicePdfAlreadyExists) {
                throw $exception;
            }

            throw new PdfDownloadFailure(self::redact(($body->failure() ?? $exception)->getMessage(), $url), retryable: true);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * A rename replaces the target atomically on local disks and most FTP servers. SFTP (and some FTP
     * servers) refuse to rename onto an existing file: only then, and only when overwriting, delete
     * the old file and rename again, rather than failing after a complete download.
     */
    private function moveIntoPlace(FilesystemOperator $filesystem, string $temporary, string $path, bool $overwrite): void
    {
        try {
            $filesystem->move($temporary, $path);
        } catch (UnableToMoveFile $exception) {
            if (! $overwrite || ! $filesystem->fileExists($path)) {
                throw $exception;
            }

            $filesystem->delete($path);
            $filesystem->move($temporary, $path);
        }
    }

    /**
     * Transport settings from beel.http.options that the download needs to reach the storage host
     * too (proxy, TLS). Never headers, auth or base_uri: those belong to BeeL's API.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(): array
    {
        $options = (array) $this->config->get('beel.http.options', []);

        return array_intersect_key($options, array_flip(['proxy', 'verify', 'cert', 'ssl_key', 'crypto_method', 'force_ip_resolve', 'version']));
    }

    private function driver(?string $disk): FilesystemOperator
    {
        $filesystem = $this->filesystems->disk($disk);

        // The League driver always throws on failure, whatever the disk's `throw` option says.
        if (! $filesystem instanceof FilesystemAdapter) {
            throw new \InvalidArgumentException('storePdf() needs a Flysystem-based Laravel disk.');
        }

        return $filesystem->getDriver();
    }

    /** The pre-signed URL is a short-lived bearer link: keep it out of messages and logs. */
    private static function redact(string $message, string $url): string
    {
        $base = strtok($url, '?');

        return str_replace([$url, is_string($base) ? $base : $url], '[pre-signed PDF URL]', $message);
    }
}
