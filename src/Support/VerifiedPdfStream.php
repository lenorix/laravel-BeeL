<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Passes a download through unchanged while counting its bytes and checking it starts with the PDF
 * signature. Guzzle doesn't detect a truncated body in stream mode, so verify() compares the count
 * with the declared length once the storage adapter has read everything.
 *
 * @internal
 */
final class VerifiedPdfStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private const SIGNATURE = '%PDF-';

    private int $bytesRead = 0;

    private string $head = '';

    private ?\UnexpectedValueException $failure = null;

    public function __construct(private StreamInterface $stream, private ?int $expectedLength) {}

    public function read(int $length): string
    {
        $data = $this->stream->read($length);
        $this->bytesRead += strlen($data);

        // Fail fast, before the adapter writes megabytes of an error page or a consumed body.
        if (strlen($this->head) < strlen(self::SIGNATURE)) {
            $this->head .= substr($data, 0, strlen(self::SIGNATURE) - strlen($this->head));
            if (strlen($this->head) === strlen(self::SIGNATURE) && $this->head !== self::SIGNATURE) {
                throw $this->failure = new \UnexpectedValueException('the download is not a PDF.');
            }
        }
        if ($this->expectedLength !== null && $this->bytesRead > $this->expectedLength) {
            throw $this->failure = new \UnexpectedValueException("the download is longer than its declared {$this->expectedLength} bytes.");
        }

        return $data;
    }

    /**
     * Why reading was stopped, if it was. Some adapters (e.g. the local one) swallow an exception
     * thrown while they read and report a generic write failure instead.
     */
    public function failure(): ?\UnexpectedValueException
    {
        return $this->failure;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    /** Throws unless the whole body was read: a PDF signature and exactly the declared length. */
    public function verify(): void
    {
        if ($this->head !== self::SIGNATURE) {
            throw new \UnexpectedValueException($this->bytesRead === 0 ? 'the download is empty.' : 'the download is not a PDF.');
        }
        if ($this->expectedLength !== null && $this->bytesRead !== $this->expectedLength) {
            throw new \UnexpectedValueException("the download was cut short: {$this->bytesRead} of {$this->expectedLength} bytes.");
        }
    }
}
