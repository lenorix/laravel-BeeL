<?php

use Illuminate\Http\Request;
use Lenorix\LaravelBeel\ConfigWebhookSecretResolver;

it('resolves the secret from config', function () {
    config()->set('services.beel.webhook_secret', 'shh-secret');

    $resolver = app(ConfigWebhookSecretResolver::class);

    expect($resolver->resolve(Request::create('/beel/webhook', 'POST')))->toBe('shh-secret');
});

it('returns null when no secret is configured', function () {
    config()->set('services.beel.webhook_secret', null);

    $resolver = app(ConfigWebhookSecretResolver::class);

    expect($resolver->resolve(Request::create('/beel/webhook', 'POST')))->toBeNull();
});

it('returns null when the configured secret is blank', function () {
    config()->set('services.beel.webhook_secret', '   ');

    $resolver = app(ConfigWebhookSecretResolver::class);

    expect($resolver->resolve(Request::create('/beel/webhook', 'POST')))->toBeNull();
});
