<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Psr\Http\Client\ClientExceptionInterface;

/** The request to BeeL failed for a reason other than the connection (PSR-18 client error). Not a BeelApiError. */
final class LaravelClientException extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
