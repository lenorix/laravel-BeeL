<?php

use Lenorix\BeelSdk\Beel;
use Lenorix\LaravelBeel\BeelCompany;
use Lenorix\LaravelBeel\BeelManager;

beforeEach(function () {
    config()->set('services.beel.key', 'config-api-key');
    config()->set('services.beel.company_id', 'config-company-id');
    config()->set('services.beel.base_url', 'https://config.example.test/api');
});

it('creates a client using the configured api key and base url', function () {
    $client = app(BeelManager::class)->client();

    expect($client)->toBeInstanceOf(Beel::class);
});

it('lets an explicit api key override the configured one', function () {
    $client = app(BeelManager::class)->client(apiKey: 'explicit-api-key');

    expect($client)->toBeInstanceOf(Beel::class);
});

it('throws when no api key is configured or provided', function () {
    config()->set('services.beel.key', null);

    app(BeelManager::class)->client();
})->throws(InvalidArgumentException::class, 'Set services.beel.key or pass a tenant API key.');

it('throws when the configured api key is blank', function () {
    config()->set('services.beel.key', '   ');

    app(BeelManager::class)->client();
})->throws(InvalidArgumentException::class);

it('throws when the configured base url is blank', function () {
    config()->set('services.beel.base_url', '   ');

    app(BeelManager::class)->client();
})->throws(InvalidArgumentException::class, 'services.beel.base_url must be a non-empty URL.');

it('creates a company scope using the configured company id', function () {
    $company = app(BeelManager::class)->company();

    expect($company)->toBeInstanceOf(BeelCompany::class)
        ->and($company->companyId)->toBe('config-company-id');
});

it('lets an explicit company id override the configured one', function () {
    $company = app(BeelManager::class)->company(companyId: 'explicit-company-id');

    expect($company->companyId)->toBe('explicit-company-id');
});

it('throws when no company id is configured or provided', function () {
    config()->set('services.beel.company_id', null);

    app(BeelManager::class)->company();
})->throws(InvalidArgumentException::class, 'Set services.beel.company_id or pass a tenant company UUID.');
