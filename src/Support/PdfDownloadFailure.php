<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

/** @internal One failed attempt of InvoicePdfStorage; its message never contains the pre-signed URL. */
final class PdfDownloadFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
