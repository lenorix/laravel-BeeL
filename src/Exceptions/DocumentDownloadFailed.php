<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/**
 * Downloading or storing a BeeL document failed after every attempt. Nothing was written to the
 * target path. The message never contains the pre-signed download URL (a short-lived bearer link).
 */
final class DocumentDownloadFailed extends \RuntimeException
{
    public function __construct(public readonly string $document, string $reason, public readonly int $attempts)
    {
        parent::__construct("Could not store {$document} after {$attempts} attempt(s): {$reason}");
    }
}
