<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Http\Discovery\Psr17FactoryDiscovery;
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

        $psrResponse = Psr17FactoryDiscovery::findResponseFactory()->createResponse($response->status());
        foreach ($response->headers() as $name => $values) {
            $psrResponse = $psrResponse->withHeader($name, $values);
        }
        $stream = Psr17FactoryDiscovery::findStreamFactory()->createStream($response->body());

        return $psrResponse->withBody($stream);
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
