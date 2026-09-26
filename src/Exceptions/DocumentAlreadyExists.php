<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/** The target path already holds a file and `overwrite: true` was not passed. Nothing was downloaded. */
final class DocumentAlreadyExists extends \RuntimeException
{
    public function __construct(public readonly string $document, public readonly string $path)
    {
        parent::__construct("Not storing {$document}: {$path} already exists (pass overwrite: true to replace it).");
    }
}
