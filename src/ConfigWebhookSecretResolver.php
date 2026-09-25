<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;

final class ConfigWebhookSecretResolver implements WebhookSecretResolver
{
    public function __construct(private ConfigRepository $config) {}

    public function resolve(Request $request): ?string
    {
        $secret = $this->config->get('services.beel.webhook_secret');

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }
}
