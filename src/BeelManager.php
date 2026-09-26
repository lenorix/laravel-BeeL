<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Lenorix\BeelSdk\Beel;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;

/**
 * Creates isolated SDK clients. Explicit arguments win; otherwise credentials come from the bound
 * CredentialsResolver (config by default).
 */
final class BeelManager
{
    public function __construct(
        private ConfigRepository $config,
        private BeelHttpClientFactory $httpClientFactory,
    ) {}

    public function client(?string $apiKey = null): Beel
    {
        $apiKey ??= $this->credentials()->apiKey();
        $baseUrl = $this->config->get('services.beel.base_url', 'https://app.beel.es/api');
        $retries = (int) $this->config->get('beel.http.retries', 3);
        $retryDelay = (int) $this->config->get('beel.http.retry_delay_ms', 100);

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException('No BeeL API key: pass one, set services.beel.key, or bind a CredentialsResolver that returns it.');
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
        $companyId ??= $this->credentials()->companyId();

        if (! is_string($companyId) || trim($companyId) === '') {
            throw new \InvalidArgumentException('No BeeL company id: pass one, set services.beel.company_id, or bind a CredentialsResolver that returns it.');
        }

        return new BeelCompany($this->client($apiKey), $companyId);
    }

    public function account(?string $apiKey = null, ?string $accountId = null): BeelAccount
    {
        $accountId ??= $this->credentials()->accountId();

        if (! is_string($accountId) || trim($accountId) === '') {
            throw new \InvalidArgumentException('No BeeL account id: pass one, set services.beel.account_id, or bind a CredentialsResolver that returns it.');
        }

        return new BeelAccount($this->client($apiKey), $accountId);
    }

    /**
     * Resolved per call from the current container, not injected: this manager is a singleton, while
     * the resolver may depend on per-request state (and Octane swaps the container per request).
     */
    private function credentials(): CredentialsResolver
    {
        return Container::getInstance()->make(CredentialsResolver::class);
    }
}
