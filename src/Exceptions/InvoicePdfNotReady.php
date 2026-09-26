<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/** BeeL is still generating the invoice PDF (it answered 202 after waiting). Try again after $retryAfter seconds. */
final class InvoicePdfNotReady extends \RuntimeException
{
    /** @param  int|null  $retryAfter  Seconds BeeL asks to wait before trying again, when it says. */
    public function __construct(public readonly string $invoiceId, public readonly ?int $retryAfter = null, ?\Throwable $previous = null)
    {
        $when = $retryAfter === null ? 'in a few seconds' : "in {$retryAfter} s";
        parent::__construct("The PDF of invoice {$invoiceId} is not ready yet; try again {$when}.", 0, $previous);
    }
}
