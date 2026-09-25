<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Client\ClientInterface;

final class BeelHttpClientFactory
{
    public function __construct(private HttpFactory $http) {}

    public function make(int $retries, int $retryDelayMs): ClientInterface
    {
        $timeout = (float) config('beel.http.timeout', 30);
        $connectTimeout = (float) config('beel.http.connect_timeout', 10);

        $request = $this->http->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withOptions((array) config('beel.http.options', []));

        if ($retries > 0) {
            // PendingRequest's retry count is the total number of attempts.
            $request->retry(
                $retries + 1,
                $retryDelayMs,
                static function ($exception): bool {
                    if (! $exception instanceof RequestException) {
                        return true;
                    }

                    $status = $exception->response->status();

                    return $status === 429 || $status >= 500;
                },
                throw: false,
            );
        }

        return new LaravelPsr18Client($request);
    }
}
