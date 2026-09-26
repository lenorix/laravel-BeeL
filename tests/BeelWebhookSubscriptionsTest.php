<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelBeel\BeelWebhookSubscription;
use Lenorix\LaravelBeel\BeelWebhookSubscriptions;
use Lenorix\LaravelBeel\Exceptions\RotatedWebhookSecretNotStored;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionNotFound;
use Lenorix\LaravelBeel\Exceptions\WebhookSubscriptionOrphaned;

beforeEach(function () {
    config()->set('app.url', 'https://app.test');
    config()->set('services.beel.key', 'beel_sk_test_key');
    config()->set('services.beel.account_id', 'acc-1');
    config()->set('services.beel.base_url', 'https://beel.test/api');
    config()->set('beel.http.retries', 0);
});

/** @param array<int, array<string, mixed>> $existing */
function fakeSubscriptionsApi(array $existing = [], string $secret = 'whsec_new', bool $deleteFails = false, string $account = 'acc-1'): void
{
    Http::fake(function (ClientRequest $request) use ($existing, $secret, $deleteFails, $account) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $sub = fn (string $id, string $url) => ['id' => $id, 'url' => $url, 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM), 'secret' => $secret];

        return match (true) {
            $request->method() === 'GET' && str_ends_with($path, "/accounts/{$account}/webhooks") => Http::response(['success' => true, 'data' => ['webhooks' => $existing, 'pagination' => ['current_page' => 1, 'total_pages' => 1, 'total_items' => count($existing), 'items_per_page' => 100, 'has_next' => false, 'has_previous' => false]]], 200),
            $request->method() === 'POST' && str_ends_with($path, "/accounts/{$account}/webhooks") => Http::response(['success' => true, 'data' => $sub('wh-new', $request['url'])], 201),
            $request->method() === 'POST' && preg_match('#/webhooks/([^/]+)/secret$#', $path, $m) === 1 => Http::response(['success' => true, 'data' => $sub($m[1], 'https://app.test/beel/webhook/tenant-a')], 200),
            $request->method() === 'DELETE' => $deleteFails ? Http::response(['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'x']], 500) : Http::response(null, 204),
            default => Http::response(['success' => false], 404),
        };
    });
}

function tenantSubscription(string $id = 'wh-1', string $url = 'https://app.test/beel/webhook/tenant-a', bool $active = true): array
{
    return ['id' => $id, 'url' => $url, 'events' => ['invoice.issued'], 'active' => $active, 'created_at' => now()->format(DATE_ATOM)];
}

it('subscribes a tenant URL and hands the secret only to the store callback', function () {
    fakeSubscriptionsApi();
    $stored = null;

    $subscription = app(BeelWebhookSubscriptions::class)->subscribe(
        store: function (string $secret) use (&$stored) {
            $stored = $secret;
        },
        webhookKey: 'tenant-a',
    );

    expect($stored)->toBe('whsec_new')
        ->and($subscription)->toBeInstanceOf(BeelWebhookSubscription::class)
        ->and($subscription->id)->toBe('wh-new')
        ->and($subscription->url)->toBe('https://app.test/beel/webhook/tenant-a')
        ->and(json_encode($subscription))->not->toContain('whsec_new')
        ->and(print_r($subscription, true))->not->toContain('whsec_new');
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
        && $r['url'] === 'https://app.test/beel/webhook/tenant-a'
        && in_array('invoice.issued', $r['events'], true)
        && ! in_array('account.claimed', $r['events'], true));
});

it('uses the given tenant credentials', function () {
    fakeSubscriptionsApi(account: 'acc-tenant');

    app(BeelWebhookSubscriptions::class)->subscribe(fn () => null, webhookKey: 'tenant-a', apiKey: 'beel_sk_test_tenant', accountId: 'acc-tenant');

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
        && str_contains($r->url(), '/accounts/acc-tenant/')
        && $r->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
});

it('url-encodes the webhook key in the URL', function () {
    expect(app(BeelWebhookSubscriptions::class)->url('tenant a/b'))->toBe('https://app.test/beel/webhook/tenant%20a%2Fb')
        ->and(app(BeelWebhookSubscriptions::class)->url())->toBe('https://app.test/beel/webhook');
});

it('refuses to create a second subscription for the same URL', function () {
    fakeSubscriptionsApi([tenantSubscription()]);
    $called = false;

    expect(fn () => app(BeelWebhookSubscriptions::class)->subscribe(function () use (&$called) {
        $called = true;
    }, webhookKey: 'tenant-a'))->toThrow(WebhookSubscriptionAlreadyExists::class);

    expect($called)->toBeFalse();
    Http::assertNotSent(fn (ClientRequest $r) => $r->method() === 'POST');
});

it('deletes the new subscription and rethrows when the store callback fails', function () {
    fakeSubscriptionsApi();

    expect(fn () => app(BeelWebhookSubscriptions::class)->subscribe(fn () => throw new RuntimeException('db down'), webhookKey: 'tenant-a'))
        ->toThrow(RuntimeException::class, 'db down');

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/webhooks/wh-new'));
});

it('reports an orphaned subscription when it can neither store the secret nor delete it', function () {
    fakeSubscriptionsApi(deleteFails: true);

    try {
        app(BeelWebhookSubscriptions::class)->subscribe(fn () => throw new RuntimeException('db down'), webhookKey: 'tenant-a');
        $this->fail('Expected WebhookSubscriptionOrphaned.');
    } catch (WebhookSubscriptionOrphaned $exception) {
        expect($exception->subscriptionId)->toBe('wh-new')
            ->and($exception->getPrevious()?->getMessage())->toBe('db down')
            ->and($exception->getMessage())->not->toContain('whsec_new');
    }
});

it('rotates the secret of an existing subscription through the store callback', function () {
    fakeSubscriptionsApi([tenantSubscription()], 'whsec_rotated');
    $stored = null;

    $subscription = app(BeelWebhookSubscriptions::class)->rotate(function (string $secret) use (&$stored) {
        $stored = $secret;
    }, webhookKey: 'tenant-a');

    expect($stored)->toBe('whsec_rotated')->and($subscription->id)->toBe('wh-1');
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/webhooks/wh-1/secret'));
});

it('fails to rotate when there is no subscription for the URL', function () {
    fakeSubscriptionsApi();

    expect(fn () => app(BeelWebhookSubscriptions::class)->rotate(fn () => null, webhookKey: 'tenant-a'))
        ->toThrow(WebhookSubscriptionNotFound::class);
});

it('hands back the rotated secret when it cannot be stored, as the old one is already invalid', function () {
    fakeSubscriptionsApi([tenantSubscription()], 'whsec_rotated');

    try {
        app(BeelWebhookSubscriptions::class)->rotate(fn () => throw new RuntimeException('db down'), webhookKey: 'tenant-a');
        $this->fail('Expected RotatedWebhookSecretNotStored.');
    } catch (RotatedWebhookSecretNotStored $exception) {
        expect($exception->secret)->toBe('whsec_rotated')
            ->and($exception->subscriptionId)->toBe('wh-1')
            ->and($exception->getMessage())->not->toContain('whsec_rotated');
    }
});

it('finds and unsubscribes a tenant subscription', function () {
    fakeSubscriptionsApi([tenantSubscription()]);
    $service = app(BeelWebhookSubscriptions::class);

    expect($service->find(webhookKey: 'tenant-a')?->id)->toBe('wh-1')
        ->and($service->find(webhookKey: 'tenant-b'))->toBeNull()
        ->and($service->unsubscribe(webhookKey: 'tenant-a'))->toBeTrue()
        ->and($service->unsubscribe(webhookKey: 'tenant-b'))->toBeFalse();
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/webhooks/wh-1'));
});

it('matches existing subscriptions ignoring a trailing slash', function () {
    fakeSubscriptionsApi([tenantSubscription(url: 'https://app.test/beel/webhook/tenant-a/')]);

    expect(app(BeelWebhookSubscriptions::class)->find(webhookKey: 'tenant-a')?->id)->toBe('wh-1');
});

it('refuses a non-HTTPS URL before calling BeeL', function () {
    config()->set('app.url', 'http://app.test');
    Http::fake();

    expect(fn () => app(BeelWebhookSubscriptions::class)->subscribe(fn () => null, webhookKey: 'tenant-a'))
        ->toThrow(InvalidArgumentException::class, 'HTTPS');

    Http::assertNothingSent();
});
