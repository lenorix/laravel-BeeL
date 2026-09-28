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
use Lenorix\BeelSdk\Http\BinaryDownload;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\StoredDocument;
use Psr\Http\Message\StreamInterface;

/**
 * Streams a document from one of BeeL's pre-signed URLs into a Laravel disk, verified and atomic.
 * See BeelCompanyInvoices::storePdf() for the guarantees.
 *
 * @internal
 */
final class SignedDownloadStorage
{
    public function __construct(
        private HttpFactory $http,
        private FilesystemFactory $filesystems,
        private ConfigRepository $config,
    ) {}

    /**
     * @param  \Closure(): string  $signedUrl  Asks BeeL for a fresh pre-signed URL; called once per attempt.
     * @param  string  $document  What is stored, for messages (e.g. "the PDF of invoice X").
     * @param  array<string, mixed>  $options
     */
    public function store(\Closure $signedUrl, DocumentKind $kind, string $document, string $path, ?string $disk, bool $overwrite, array $options): string
    {
        $filesystem = $this->driver($disk);

        // Before calling BeeL, so an existing file costs no API call and no download.
        if (! $overwrite && $filesystem->fileExists($path)) {
            throw new DocumentAlreadyExists($document, $path);
        }

        // An image's real format can differ from what BeeL declares: let the disk infer it rather than
        // stamp a possibly wrong type.
        if ($kind !== DocumentKind::Image) {
            $options += ['ContentType' => $kind->contentType()];
        }

        $this->withFreshUrls($signedUrl, $document, fn (string $url) => $this->downloadInto($filesystem, $url, $kind, $path, $overwrite, $options, $document));

        return $path;
    }

    /**
     * Stores a file BeeL's API returns in the response body (an invoice archive, an export, a draft's
     * PDF preview), streamed and verified like store(). A single attempt: the SDK already retried the
     * request where that is safe, and re-requesting an archive or export makes BeeL build it again.
     *
     * @param  \Closure(): BinaryDownload  $download  Makes the request; called only when $path may be written.
     * @param  array<string, mixed>  $options
     */
    public function storeDownload(\Closure $download, DocumentKind $kind, string $document, string $path, ?string $disk, bool $overwrite, array $options): StoredDocument
    {
        $filesystem = $this->driver($disk);

        // Before calling BeeL, so an existing file costs no API call and no download.
        if (! $overwrite && $filesystem->fileExists($path)) {
            throw new DocumentAlreadyExists($document, $path);
        }

        $file = $download();

        try {
            $this->writeVerified($filesystem, $file->body, $file->contentLength, $kind, $path, $overwrite, $options + ['ContentType' => DocumentKind::withCharset($file->contentType ?? $kind->contentType(), $file->charset)], $document, fn (string $message) => $message);
        } catch (DownloadFailure $failure) {
            throw new DocumentDownloadFailed($document, $failure->getMessage(), 1);
        }

        return new StoredDocument($path, $file->fileName, $file->counts);
    }

    /** @param array<string, mixed> $options */
    private function downloadInto(FilesystemOperator $filesystem, string $url, DocumentKind $kind, string $path, bool $overwrite, array $options, string $document): void
    {
        [$stream, $length] = $this->requestSigned($url);

        $this->writeVerified($filesystem, $stream, $length, $kind, $path, $overwrite, $options, $document, fn (string $message) => self::redact($message, $url));
    }

    /**
     * Opens a download from one of BeeL's pre-signed URLs, retrying with a new URL like store(), for a
     * caller that streams it somewhere else (e.g. an HTTP response). The body is not read yet.
     *
     * @param  \Closure(): string  $signedUrl  Asks BeeL for a fresh pre-signed URL; called once per attempt.
     * @return array{StreamInterface, ?int, ?string} The body, its declared length and content type.
     *
     * @throws DocumentDownloadFailed
     */
    public function openSigned(\Closure $signedUrl, string $document): array
    {
        return $this->withFreshUrls($signedUrl, $document, fn (string $url) => $this->requestSigned($url));
    }

    /**
     * Runs $attempt with a fresh pre-signed URL (they expire after five minutes) until it succeeds, a
     * failure is not retryable, or beel.downloads.attempts run out, pausing beel.http.retry_delay_ms
     * between attempts.
     *
     * @template T
     *
     * @param  \Closure(): string  $signedUrl
     * @param  \Closure(string): T  $attempt
     * @return T
     *
     * @throws DocumentDownloadFailed
     */
    private function withFreshUrls(\Closure $signedUrl, string $document, \Closure $attempt): mixed
    {
        $attempts = max(1, Settings::int('beel.downloads.attempts', 3));

        for ($number = 1; ; $number++) {
            try {
                return $attempt($signedUrl());
            } catch (DownloadFailure $failure) {
                if (! $failure->retryable || $number >= $attempts) {
                    throw new DocumentDownloadFailed($document, $failure->getMessage(), $number);
                }

                Sleep::usleep(max(0, Settings::int('beel.http.retry_delay_ms', 500)) * 1000);
            }
        }
    }

    /**
     * @return array{StreamInterface, ?int, ?string} The unread body, its declared length and content type.
     *
     * @throws DownloadFailure
     */
    private function requestSigned(string $url): array
    {
        // A plain request: the pre-signed URL carries its own authorization, so BeeL's API key must
        // never reach it, and a half-read stream can't be retried by middleware anyway.
        try {
            $response = $this->http->withOptions($this->transportOptions() + [
                'stream' => true,
                'read_timeout' => Settings::float('beel.downloads.read_timeout', 30),
                'connect_timeout' => Settings::float('beel.http.connect_timeout', 10),
                'timeout' => 0,
            ])->get($url);
        } catch (ConnectionException $exception) {
            throw new DownloadFailure(self::redact($exception->getMessage(), $url), retryable: true);
        }

        $status = $response->status();
        if ($status !== 200) {
            $reason = "the download answered HTTP {$status}.";

            // 403 is an expired or rejected pre-signed URL: a new one may work.
            throw new DownloadFailure($reason, retryable: in_array($status, [403, 408, 429], true) || $status >= 500);
        }

        // Guzzle drops Content-Length when it decodes a Content-Encoding, so a remaining one always
        // describes the bytes read here.
        $length = $response->header('Content-Length');
        $type = $response->header('Content-Type');

        return [$response->toPsrResponse()->getBody(), ctype_digit($length) ? (int) $length : null, $type !== '' ? $type : null];
    }

    /**
     * Streams $stream into a temporary file next to $path, checks it (signature, declared length,
     * stored size) and only then moves it into place. Any failure removes the temporary file and
     * leaves an existing $path untouched.
     *
     * @param  array<string, mixed>  $options
     * @param  \Closure(string): string  $redact  Keeps secrets (a pre-signed URL) out of messages.
     *
     * @throws DocumentAlreadyExists
     * @throws DownloadFailure
     */
    private function writeVerified(FilesystemOperator $filesystem, StreamInterface $stream, ?int $length, DocumentKind $kind, string $path, bool $overwrite, array $options, string $document, \Closure $redact): void
    {
        $body = new VerifiedDownloadStream($stream, $length, $kind);

        // Same directory, so the final move is a rename on local disks; ends like the target so disks
        // that guess the MIME type from the extension (S3, GCS) keep treating it as the right type.
        $directory = dirname($path);
        $temporary = ($directory === '.' ? '' : $directory.'/').'.beel-'.Str::random(16).'-'.basename($path);

        $resource = StreamWrapper::getResource($body);
        stream_set_chunk_size($resource, max(8192, Settings::int('beel.downloads.buffer_bytes', 65536)));

        try {
            $filesystem->writeStream($temporary, $resource, $options);
            $body->verify();

            $stored = $filesystem->fileSize($temporary);
            if ($stored !== $body->bytesRead()) {
                throw new \UnexpectedValueException("the disk stored {$stored} of {$body->bytesRead()} bytes.");
            }

            // Checked again right before replacing, for a file created during the download.
            if (! $overwrite && $filesystem->fileExists($path)) {
                throw new DocumentAlreadyExists($document, $path);
            }

            $this->moveIntoPlace($filesystem, $temporary, $path, $overwrite);
        } catch (\Throwable $exception) {
            try {
                $filesystem->delete($temporary);
            } catch (\Throwable) {
                // Best effort: the original failure matters more than a leftover temporary file.
            }

            if ($exception instanceof DocumentAlreadyExists) {
                throw $exception;
            }

            throw new DownloadFailure($redact(($body->failure() ?? $exception)->getMessage()), retryable: true);
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
            throw new \InvalidArgumentException('Storing BeeL documents needs a Flysystem-based Laravel disk.');
        }

        return $filesystem->getDriver();
    }

    /** The pre-signed URL is a short-lived bearer link: keep it out of messages and logs. */
    private static function redact(string $message, string $url): string
    {
        $base = strtok($url, '?');

        return str_replace([$url, is_string($base) ? $base : $url], '[pre-signed URL]', $message);
    }
}
