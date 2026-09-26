<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Http;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelHttpClientFactory;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\ConfigCredentialsResolver;
use Lenorix\LaravelBeel\ConfigWebhookSecretResolver;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
use Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\CredentialsWebhookRetryAccounts;
use Lenorix\LaravelBeel\Facades\LaravelBeel;
use Lenorix\LaravelBeel\Testing\BeelFake;

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

it('binds WebhookRetryAccounts to the credentials-based provider by default', function () {
    expect(app(WebhookRetryAccounts::class))->toBeInstanceOf(CredentialsWebhookRetryAccounts::class);
});

it('resolves the facade to BeelManager', function () {
    expect(LaravelBeel::getFacadeRoot())->toBeInstanceOf(BeelManager::class);
});

it('publishes the beel config file with expected defaults', function () {
    expect(config('beel.register_webhook_route'))->toBeTrue()
        ->and(config('beel.webhook_path'))->toBe('beel/webhook')
        ->and(config('beel.webhook_rate_limit'))->toBeNull()
        ->and(config('beel.webhook_replay_tolerance_seconds'))->toBe(300)
        ->and(config('beel.webhook_dedupe_seconds'))->toBe(900)
        ->and(config('beel.webhook_dedupe_store'))->toBeNull()
        ->and(config('beel.http.retries'))->toBe(3);
});

it('adds the BeeL request id, error code and status to the log context of reported errors', function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Http::fake(['*' => BeelFake::error(422, 'EMISSION_NOT_READY', details: ['nif' => 'B12345674'])]);

    try {
        app(BeelManager::class)->company()->invoices->issue('inv-1');
    } catch (BeelApiError $error) {
    }

    $handler = app(ExceptionHandler::class);

    $expected = ['request_id' => BeelFake::REQUEST_ID, 'api_code' => 'EMISSION_NOT_READY', 'status_code' => 422];

    expect($handler->buildContextForException($error))->toMatchArray($expected)
        ->and($handler->buildContextForException(new RuntimeException('Issuing failed', previous: new LogicException('step', previous: $error))))->toMatchArray($expected)
        ->and($handler->buildContextForException($error))->not->toHaveKey('details') // may echo submitted NIFs or amounts
        ->and($handler->buildContextForException(new RuntimeException('unrelated')))->not->toHaveKey('request_id');
});
