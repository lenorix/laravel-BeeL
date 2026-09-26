<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('app.url', 'https://app.test');
    config()->set('app.env', 'local');
    config()->set('services.beel.key', 'beel_sk_test_key');
    config()->set('services.beel.account_id', 'acc-1');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('services.beel.base_url', 'https://beel.test/api');
    config()->set('services.beel.webhook_secret', 'whsec');
    config()->set('beel.webhook_dedupe_store', 'database');
    config()->set('beel.http.retries', 0);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeBeelCheckApi(array $overrides = []): void
{
    $o = array_merge([
        'identity_status' => 200,
        'account_id' => 'acc-1',
        'environment' => 'TEST',
        'scopes' => ['invoices:write', 'webhooks:read', 'webhooks:write'],
        'ready' => true,
        'blockers' => [],
        'subscriptions' => [['id' => 'wh-1', 'url' => 'https://app.test/beel/webhook', 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM)]],
    ], $overrides);

    Http::fake(function (ClientRequest $request) use ($o) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/v1/me/identity') => $o['identity_status'] === 200
                ? Http::response(['success' => true, 'data' => ['account_id' => $o['account_id'], 'email' => 'owner@example.test', 'language' => 'es', 'credential' => ['type' => 'api_key', 'environment' => $o['environment'], 'scopes' => $o['scopes']]]], 200)
                : Http::response(['success' => false, 'error' => ['code' => 'INVALID_API_KEY', 'message' => 'no']], $o['identity_status']),
            str_ends_with($path, '/issuing-readiness') => Http::response(['success' => true, 'data' => ['ready' => $o['ready'], 'blockers' => $o['blockers']]], 200),
            str_ends_with($path, '/accounts/acc-1/webhooks') => Http::response(['success' => true, 'data' => ['webhooks' => $o['subscriptions'], 'pagination' => ['current_page' => 1, 'total_pages' => 1, 'total_items' => count($o['subscriptions']), 'items_per_page' => 100, 'has_next' => false, 'has_previous' => false]]], 200),
            default => Http::response(['success' => false], 404),
        };
    });
}

it('passes on a healthy setup without making any change in BeeL', function () {
    fakeBeelCheckApi();

    $this->artisan('beel:check')->assertSuccessful();

    Http::assertNotSent(fn (ClientRequest $request) => $request->method() !== 'GET');
});

it('fails when there is no API key', function () {
    config()->set('services.beel.key', null);
    Http::fake();

    $this->artisan('beel:check')->expectsOutputToContain('API key')->assertFailed();
    Http::assertNothingSent();
});

it('fails when BeeL rejects the API key', function () {
    fakeBeelCheckApi(['identity_status' => 401]);

    $this->artisan('beel:check')->expectsOutputToContain('rejected')->assertFailed();
});

it('fails when the key belongs to another account than the configured one', function () {
    fakeBeelCheckApi(['account_id' => 'acc-other']);

    $this->artisan('beel:check')->expectsOutputToContain('acc-other')->assertFailed();
});

it('warns when a sandbox key is used in production', function () {
    app()->detectEnvironment(fn () => 'production');
    fakeBeelCheckApi();

    $this->artisan('beel:check')->expectsOutputToContain('sandbox')->assertSuccessful();
});

it('fails and lists the blockers when the company cannot issue invoices', function () {
    fakeBeelCheckApi(['ready' => false, 'blockers' => ['NIF_REPRESENTATION_REQUIRED']]);

    $this->artisan('beel:check')->expectsOutputToContain('NIF_REPRESENTATION_REQUIRED')->assertFailed();
});

it('warns about the webhook scopes the retry command needs', function () {
    fakeBeelCheckApi(['scopes' => ['invoices:write']]);

    $this->artisan('beel:check')->expectsOutputToContain('webhooks:write')->assertSuccessful();
});

it('warns an integrator key whose subscription misses the integrator events', function () {
    fakeBeelCheckApi(['scopes' => ['accounts:read', 'accounts:write', 'webhooks:read', 'webhooks:write']]);

    $this->artisan('beel:check')
        ->expectsOutputToContain('integrator scopes')
        ->expectsOutputToContain('account.claimed, company.created, representation.signed')
        ->assertSuccessful();
});

it('does not warn an integrator key subscribed to the integrator events', function () {
    fakeBeelCheckApi([
        'scopes' => ['accounts:read', 'webhooks:read', 'webhooks:write'],
        'subscriptions' => [['id' => 'wh-1', 'url' => 'https://app.test/beel/webhook', 'events' => ['invoice.issued', 'account.claimed', 'company.created', 'representation.signed'], 'account_relationship' => 'all', 'active' => true, 'created_at' => now()->format(DATE_ATOM)]],
    ]);

    $this->artisan('beel:check')
        ->doesntExpectOutputToContain('integrator events')
        ->doesntExpectOutputToContain('only receives events from your own account')
        ->assertSuccessful();
});

it('warns an integrator whose subscription only receives its own account events', function () {
    fakeBeelCheckApi([
        'scopes' => ['accounts:read', 'webhooks:read', 'webhooks:write'],
        'subscriptions' => [['id' => 'wh-1', 'url' => 'https://app.test/beel/webhook', 'events' => ['invoice.issued'], 'account_relationship' => 'own', 'active' => true, 'created_at' => now()->format(DATE_ATOM)]],
    ]);

    $this->artisan('beel:check')->expectsOutputToContain('--account-relationship=all')->assertSuccessful();
});

it('recognizes an integrator key even without webhook scopes', function () {
    fakeBeelCheckApi(['scopes' => ['accounts:read']]);

    $this->artisan('beel:check')->expectsOutputToContain('integrator scopes')->assertSuccessful();
});

it('says nothing about integrator events to a regular key', function () {
    fakeBeelCheckApi();

    $this->artisan('beel:check')->doesntExpectOutputToContain('integrator')->assertSuccessful();
});

it('fails when webhook deduplication uses a store that silently does nothing', function () {
    config()->set('beel.webhook_dedupe_store', 'array');
    fakeBeelCheckApi();

    $this->artisan('beel:check')->expectsOutputToContain('array')->assertFailed();
});

it('warns when the dedupe store only protects a single server', function () {
    config()->set('beel.webhook_dedupe_store', 'file');
    fakeBeelCheckApi();

    $this->artisan('beel:check')->expectsOutputToContain('single server')->assertSuccessful();
});

it('warns when no webhook subscription points at this app', function () {
    fakeBeelCheckApi(['subscriptions' => [['id' => 'wh-9', 'url' => 'https://elsewhere.test/hook', 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM)]]]);

    $this->artisan('beel:check')->expectsOutputToContain('https://app.test/beel/webhook')->assertSuccessful();
});

it('accepts per-tenant subscription URLs under the webhook route', function () {
    fakeBeelCheckApi(['subscriptions' => [['id' => 'wh-2', 'url' => 'https://app.test/beel/webhook/tenant-a', 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM)]]]);

    $this->artisan('beel:check')->doesntExpectOutputToContain('No BeeL webhook subscription')->assertSuccessful();
});

it('fails when the subscription pointing at this app is inactive', function () {
    fakeBeelCheckApi(['subscriptions' => [['id' => 'wh-1', 'url' => 'https://app.test/beel/webhook', 'events' => ['invoice.issued'], 'active' => false, 'created_at' => now()->format(DATE_ATOM)]]]);

    $this->artisan('beel:check')->expectsOutputToContain('inactive')->assertFailed();
});

it('warns when no webhook secret is configured', function () {
    config()->set('services.beel.webhook_secret', null);
    fakeBeelCheckApi();

    $this->artisan('beel:check')->expectsOutputToContain('webhook_secret')->assertSuccessful();
});

it('fails when two subscriptions deliver to the same URL, since one signs with a secret the app cannot verify', function () {
    $sub = fn (string $id, string $url) => ['id' => $id, 'url' => $url, 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM)];
    fakeBeelCheckApi(['subscriptions' => [$sub('wh-1', 'https://app.test/beel/webhook'), $sub('wh-2', 'https://app.test/beel/webhook/')]]);

    $this->artisan('beel:check')->expectsOutputToContain('wh-2')->assertFailed();
});

it('checks one tenant with the given key, company and account instead of the defaults', function () {
    config()->set('services.beel.key', null);
    config()->set('services.beel.company_id', null);
    config()->set('services.beel.account_id', null);
    fakeBeelCheckApi(['blockers' => ['REPRESENTATION_NOT_SIGNED'], 'ready' => false]);

    $this->artisan('beel:check', ['--api-key' => 'beel_sk_test_tenant', '--company-id' => 'company-t', '--account-id' => 'acc-1'])
        ->expectsOutputToContain('REPRESENTATION_NOT_SIGNED')
        ->assertFailed();

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/companies/company-t/issuing-readiness') && $r->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/accounts/acc-1/webhooks') && $r->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
    Http::assertNotSent(fn (ClientRequest $r) => ! $r->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
});

it('fails when the tenant key belongs to another account than --account-id', function () {
    fakeBeelCheckApi();

    $this->artisan('beel:check', ['--api-key' => 'beel_sk_test_tenant', '--account-id' => 'acc-other'])
        ->expectsOutputToContain('acc-other')
        ->assertFailed();
});
