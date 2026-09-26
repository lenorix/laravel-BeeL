<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Bridges PSR-18 requests into Laravel's HTTP client and its middleware/events. */
final class LaravelPsr18Client implements ClientInterface
{
    public function __construct(private PendingRequest $pendingRequest) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $pending = clone $this->pendingRequest;
        $pending->withHeaders($request->getHeaders());
        $body = (string) $request->getBody();
        if ($body !== '') {
            $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/octet-stream');
        }

        try {
            $response = $pending->send($request->getMethod(), (string) $request->getUri());
        } catch (ConnectionException $exception) {
            throw new LaravelNetworkException($exception->getMessage(), $request, $exception);
        } catch (\Throwable $exception) {
            throw new LaravelClientException($exception->getMessage(), $exception);
        }

        // Guzzle's own PSR-7 response: its body stays in php://temp (spilling to disk past 2 MB)
        // instead of being copied into a PHP string, so a large export doesn't take its size in memory.
        return $response->toPsrResponse();
    }
}

final class LaravelClientException extends \RuntimeException implements ClientExceptionInterface
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

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
