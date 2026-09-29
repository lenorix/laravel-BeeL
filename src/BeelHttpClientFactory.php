<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Lenorix\LaravelBeel\Support\Settings;
use Psr\Http\Client\ClientInterface;

/**
 * Builds the PSR-18 transport the SDK sends through: Laravel's HTTP client with the configured
 * timeouts and Guzzle options. It never retries: the SDK does (see BeelManager), since only it
 * knows which requests are safe to repeat.
 */
final class BeelHttpClientFactory
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
    ) {}

    public function make(): ClientInterface
    {
        $request = $this->http->timeout(Settings::float('beel.http.timeout', 30))
            ->connectTimeout(Settings::float('beel.http.connect_timeout', 10))
            ->withOptions((array) $this->config->get('beel.http.options', []))
            // After the app's options, so none can turn it back on: the SDK reads a 3xx as an error,
            // and following one would send the API key elsewhere or make a write look done.
            ->withoutRedirecting();

        return new LaravelPsr18Client($request);
    }
}
