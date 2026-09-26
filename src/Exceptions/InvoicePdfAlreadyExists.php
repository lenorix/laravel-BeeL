<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/** The target path already holds a file and `overwrite: true` was not passed. */
final class InvoicePdfAlreadyExists extends \RuntimeException
{
    public function __construct(public readonly string $invoiceId, public readonly string $path)
    {
        parent::__construct("Not storing the PDF of invoice {$invoiceId}: {$path} already exists (pass overwrite: true to replace it).");
    }
}
