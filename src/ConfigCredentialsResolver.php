<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;

/** Default credentials from config/services.php (services.beel.key, account_id, company_id). */
final class ConfigCredentialsResolver implements CredentialsResolver
{
    public function __construct(private ConfigRepository $config) {}

    public function apiKey(): ?string
    {
        return $this->string('services.beel.key');
    }

    public function accountId(): ?string
    {
        return $this->string('services.beel.account_id');
    }

    public function companyId(): ?string
    {
        return $this->string('services.beel.company_id');
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
