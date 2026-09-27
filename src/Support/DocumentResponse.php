<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a download from BeeL into a streamed HTTP response for the browser: nothing is stored and
 * memory stays at one buffer whatever the file's size.
 *
 * The file's signature is checked before the response is built, so a failure or an error page from
 * BeeL throws in the controller, where the app can still answer with an error, instead of reaching
 * the user as a broken file. Once streaming has started, a cut-short download can no longer be
 * turned into an error: the declared Content-Length lets the browser mark it as failed.
 *
 * @internal
 */
final class DocumentResponse
{
    /** @throws DocumentDownloadFailed The body is not the expected kind of file. */
    public static function make(StreamInterface $body, ?int $length, ?string $contentType, DocumentKind $kind, string $document, string $fileName): StreamedResponse
    {
        $head = '';
        while (strlen($head) < $kind->headLength() && ! $body->eof()) {
            $chunk = $body->read($kind->headLength() - strlen($head));
            if ($chunk === '') {
                break;
            }
            $head .= $chunk;
        }

        if (! $kind->matches($head)) {
            $body->close();

            throw new DocumentDownloadFailed($document, $head === '' ? 'the download is empty.' : "the download is not {$kind->label()}.", 1);
        }

        // An image's format is only known now: give a name without extension the right one.
        if ($kind === DocumentKind::Image && pathinfo($fileName, PATHINFO_EXTENSION) === '') {
            $fileName .= '.'.substr($kind->contentTypeOf($head), strlen('image/'));
        }

        $buffer = max(8192, Settings::int('beel.downloads.buffer_bytes', 65536));
        $headers = [
            'Content-Type' => $kind === DocumentKind::Image ? $kind->contentTypeOf($head) : ($contentType ?? $kind->contentType()),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, self::asciiFallback($fileName)),
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($length !== null) {
            $headers['Content-Length'] = (string) $length;
        }

        return new StreamedResponse(static function () use ($head, $body, $buffer): void {
            echo $head;
            while (! $body->eof()) {
                $chunk = $body->read($buffer);
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                flush();
            }
            $body->close();
        }, 200, $headers);
    }

    private static function asciiFallback(string $fileName): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\%\/]/', '_', $fileName);

        return is_string($ascii) && $ascii !== '' ? $ascii : 'download';
    }
}
