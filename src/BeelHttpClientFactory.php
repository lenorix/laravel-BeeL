<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Lenorix\LaravelBeel\Support\Settings;
use Psr\Http\Client\ClientInterface;

final class BeelHttpClientFactory
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
    ) {}

    public function make(int $retries, int $retryDelayMs): ClientInterface
    {
        $timeout = Settings::float('beel.http.timeout', 30);
        $connectTimeout = Settings::float('beel.http.connect_timeout', 10);

        $request = $this->http->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withOptions((array) $this->config->get('beel.http.options', []));

        if ($retries > 0) {
            // PendingRequest's retry count is the total number of attempts.
            $request->retry(
                $retries + 1,
                static function (int $attempt, mixed $exception) use ($retryDelayMs): int {
                    if ($exception instanceof RequestException) {
                        $retryAfter = self::retryAfterMs($exception->response);
                        if ($retryAfter !== null) {
                            return $retryAfter;
                        }
                    }

                    return $retryDelayMs;
                },
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

    /**
     * A 429's Retry-After tells us how long BeeL wants us to wait, which is more accurate than our
     * fixed delay. BeeL's rate limiter uses a fixed 60-second window, so anything larger is capped
     * defensively. Only the numeric-seconds form is handled, which is what BeeL's docs describe;
     * an HTTP-date value is ignored and falls back to the configured delay.
     */
    private static function retryAfterMs(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return min((int) $header, 60) * 1000;
    }
}
