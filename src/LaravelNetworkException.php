<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** BeeL could not be reached (connection failure, timeout). Not a BeelApiError; safe to retry. */
final class LaravelNetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
