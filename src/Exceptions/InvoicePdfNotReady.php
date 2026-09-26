<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/** BeeL is still generating the invoice PDF (it answered 202 after waiting). Try again shortly. */
final class InvoicePdfNotReady extends \RuntimeException
{
    public function __construct(public readonly string $invoiceId)
    {
        parent::__construct("The PDF of invoice {$invoiceId} is not ready yet; try again in a few seconds.");
    }
}
