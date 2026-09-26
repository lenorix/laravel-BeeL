<?php

use Lenorix\LaravelBeel\BeelHttpClientFactory;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\ConfigCredentialsResolver;
use Lenorix\LaravelBeel\ConfigWebhookSecretResolver;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Facades\LaravelBeel;

it('binds BeelManager as a singleton', function () {
    expect(app(BeelManager::class))->toBe(app(BeelManager::class));
});

it('binds BeelHttpClientFactory as a singleton', function () {
    expect(app(BeelHttpClientFactory::class))->toBe(app(BeelHttpClientFactory::class));
});

it('binds WebhookSecretResolver to the config-based resolver by default', function () {
    expect(app(WebhookSecretResolver::class))->toBeInstanceOf(ConfigWebhookSecretResolver::class);
});

it('binds CredentialsResolver to the config-based resolver by default', function () {
    expect(app(CredentialsResolver::class))->toBeInstanceOf(ConfigCredentialsResolver::class);
});

it('resolves the facade to BeelManager', function () {
    expect(LaravelBeel::getFacadeRoot())->toBeInstanceOf(BeelManager::class);
});

it('publishes the beel config file with expected defaults', function () {
    expect(config('beel.register_webhook_route'))->toBeTrue()
        ->and(config('beel.webhook_path'))->toBe('beel/webhook')
        ->and(config('beel.webhook_rate_limit'))->toBeNull()
        ->and(config('beel.webhook_replay_tolerance_seconds'))->toBe(300)
        ->and(config('beel.http.retries'))->toBe(3);
});
