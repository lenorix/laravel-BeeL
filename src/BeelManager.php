<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Lenorix\BeelSdk\Beel;

/** Creates isolated SDK clients using application defaults or tenant credentials. */
final class BeelManager
{
    public function __construct(
        private ConfigRepository $config,
        private BeelHttpClientFactory $httpClientFactory,
    ) {}

    public function client(?string $apiKey = null): Beel
    {
        $apiKey ??= $this->config->get('services.beel.key');
        $baseUrl = $this->config->get('services.beel.base_url', 'https://app.beel.es/api');
        $retries = (int) $this->config->get('beel.http.retries', 3);
        $retryDelay = (int) $this->config->get('beel.http.retry_delay_ms', 100);

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException('Set services.beel.key or pass a tenant API key.');
        }
        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new \InvalidArgumentException('services.beel.base_url must be a non-empty URL.');
        }

        $client = new Beel(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            // Laravel owns retries for this transport. The SDK still adds Idempotency-Key.
            maxRetries: 0,
            autoIdempotencyKey: true,
            httpClient: $this->httpClientFactory->make($retries, $retryDelay),
        );

        return $client;
    }

    public function company(?string $apiKey = null, ?string $companyId = null): BeelCompany
    {
        $companyId ??= $this->config->get('services.beel.company_id');

        if (! is_string($companyId) || trim($companyId) === '') {
            throw new \InvalidArgumentException('Set services.beel.company_id or pass a tenant company UUID.');
        }

        return new BeelCompany($this->client($apiKey), $companyId);
    }
}
