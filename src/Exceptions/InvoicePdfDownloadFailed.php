<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/**
 * Downloading or storing an invoice PDF failed after every attempt. Nothing was written to the
 * target path. The message never contains the pre-signed download URL (a short-lived bearer link).
 */
final class InvoicePdfDownloadFailed extends \RuntimeException
{
    public function __construct(public readonly string $invoiceId, string $reason, public readonly int $attempts, ?\Throwable $previous = null)
    {
        parent::__construct("Could not store the PDF of invoice {$invoiceId} after {$attempts} attempt(s): {$reason}", 0, $previous);
    }
}
