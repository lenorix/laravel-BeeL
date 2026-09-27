<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Lenorix\LaravelBeel\Support\Settings;
use Psr\Http\Client\ClientInterface;
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
        // A file download (Accept names a file type first, JSON only for errors) is streamed from the
        // socket instead of buffered, so a large export or archive never sits whole in memory or on
        // disk. The SDK hands its body on as a stream; errors and JSON it reads itself.
        if (self::expectsFile($request)) {
            $pending->withOptions(['stream' => true, 'read_timeout' => Settings::float('beel.downloads.read_timeout', 30)]);
        }

        try {
            $response = $pending->send($request->getMethod(), (string) $request->getUri());
        } catch (ConnectionException $exception) {
            throw new LaravelNetworkException($exception->getMessage(), $request, $exception);
        } catch (\Throwable $exception) {
            throw new LaravelClientException($exception->getMessage(), $exception);
        }

        // Guzzle's own PSR-7 response, not a copy: a streamed download stays streamed.
        return $response->toPsrResponse();
    }

    private static function expectsFile(RequestInterface $request): bool
    {
        $first = strtolower(trim(explode(',', $request->getHeaderLine('Accept'))[0]));

        return $first !== '' && ! str_contains($first, 'json') && $first !== '*/*';
    }
}
