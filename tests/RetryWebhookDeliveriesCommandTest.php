<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lenorix\LaravelBeel\AccountCredentials;
use Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts;
use Lenorix\LaravelBeel\Events\BeelWebhookDeliveryAbandoned;
use Lenorix\LaravelBeel\Events\BeelWebhookSubscriptionInactive;

const BEEL_ACCOUNTS_URL = 'https://beel.test/api/v1/accounts/acc-1';

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_key');
    config()->set('services.beel.account_id', 'acc-1');
    config()->set('services.beel.base_url', 'https://beel.test/api');
    config()->set('beel.webhook_delivery_retry.max_age_minutes', 1440);
    config()->set('beel.webhook_delivery_retry.max_attempts', 8);
    config()->set('beel.http.retries', 0); // count each retry request exactly once, without real sleeps
});

function beelSubscription(string $id, bool $active = true): array
{
    return ['id' => $id, 'url' => 'https://app.test/beel/webhook', 'events' => ['invoice.issued'], 'active' => $active, 'created_at' => now()->subYear()->format(DATE_ATOM)];
}

function beelDelivery(string $id, string $eventId, int $attempt, bool $success, int $minutesAgo): array
{
    return [
        'id' => $id,
        'subscription_id' => 'wh-1',
        'webhook_event_id' => $eventId,
        'event_type' => 'invoice.issued',
        'attempt_number' => $attempt,
        'http_status' => $success ? 202 : 503,
        'success' => $success,
        'delivered_at' => now()->subMinutes($minutesAgo)->format(DATE_ATOM),
    ];
}

function beelPage(string $key, array $items, bool $hasNext = false, int $page = 1): array
{
    return ['success' => true, 'data' => [
        $key => $items,
        'pagination' => ['current_page' => $page, 'total_pages' => $hasNext ? $page + 1 : $page, 'total_items' => count($items), 'items_per_page' => 100, 'has_next' => $hasNext, 'has_previous' => $page > 1],
    ]];
}

/**
 * @param  array<int, array>  $subscriptions
 * @param  array<string, array<int, array>>  $deliveryPages  pages of deliveries per subscription id
 */
function fakeBeelWebhookApi(array $subscriptions, array $deliveryPages, int $retryStatus = 200): void
{
    Http::fake(function (ClientRequest $request) use ($subscriptions, $deliveryPages, $retryStatus) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($request->method() === 'POST' && preg_match('#/deliveries/([^/]+)/retry$#', $path, $m)) {
            return $retryStatus === 200
                ? Http::response(['success' => true, 'data' => ['id' => 'retry-of-'.$m[1]]], 200)
                : Http::response(['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'boom']], $retryStatus);
        }

        if (preg_match('#/webhooks/([^/]+)/deliveries$#', $path, $m)) {
            $pages = $deliveryPages[$m[1]] ?? [[]];
            $page = (int) ($query['page'] ?? 1);

            return Http::response(beelPage('deliveries', $pages[$page - 1] ?? [], $page < count($pages), $page), 200);
        }

        if (str_ends_with($path, '/accounts/acc-1/webhooks')) {
            return Http::response(beelPage('webhooks', $subscriptions), 200);
        }

        return Http::response(['success' => false], 404);
    });
}

function retriedDeliveryIds(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => $pair[0]->method() === 'POST')
        ->map(fn ($pair) => preg_replace('#.*/deliveries/([^/]+)/retry$#', '$1', parse_url($pair[0]->url(), PHP_URL_PATH)))
        ->values()
        ->all();
}

it('asks BeeL to retry an event whose every attempt failed', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('d3', 'evt-1', 3, false, 5),
        beelDelivery('d2', 'evt-1', 2, false, 6),
        beelDelivery('d1', 'evt-1', 1, false, 7),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['d3']);
});

it('does not retry an event that was delivered at least once', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('d2', 'evt-1', 2, true, 5),
        beelDelivery('d1', 'evt-1', 1, false, 6),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    expect(retriedDeliveryIds())->toBe([]);
});

it('ignores events older than the configured max age, measured from their first attempt', function () {
    config()->set('beel.webhook_delivery_retry.max_age_minutes', 60);

    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('recent-retry', 'evt-old', 6, false, 5),
        beelDelivery('old-first', 'evt-old', 1, false, 120),
        beelDelivery('fresh', 'evt-new', 1, false, 10),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['fresh']);
});

it('gives up on events that reached the max attempts and fails so monitoring notices', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('d3', 'evt-1', 3, false, 5),
        beelDelivery('d9', 'evt-2', 1, false, 5),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries', ['--max-attempts' => 3])
        ->expectsOutputToContain('evt-1')
        ->assertFailed();

    expect(retriedDeliveryIds())->toBe(['d9']);
});

it('logs and dispatches an event for an abandoned delivery so the app can recover it', function () {
    Event::fake([BeelWebhookDeliveryAbandoned::class]);
    Log::spy();

    $payload = ['id' => 'evt-1', 'type' => 'invoice.voided', 'company_id' => 'company-uuid', 'data' => ['invoice_id' => 'inv-1']];
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        array_merge(beelDelivery('d3', 'evt-1', 3, false, 5), ['event_type' => 'invoice.voided', 'error_message' => 'HTTP 500', 'payload' => json_encode($payload)]),
        array_merge(beelDelivery('d1', 'evt-1', 1, false, 50), ['event_type' => 'invoice.voided']),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries', ['--max-attempts' => 3])->assertFailed();

    Event::assertDispatched(BeelWebhookDeliveryAbandoned::class, fn (BeelWebhookDeliveryAbandoned $event) => $event->accountId === 'acc-1'
        && $event->subscriptionId === 'wh-1'
        && $event->eventId === 'evt-1'
        && $event->eventType === 'invoice.voided'
        && $event->attempts === 3
        && $event->lastDeliveryId === 'd3'
        && $event->lastHttpStatus === 503
        && $event->lastError === 'HTTP 500'
        && $event->payload === $payload);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'evt-1')
        && $context['attempts'] === 3);
});

it('does not dispatch the abandoned event while an event is still being retried', function () {
    Event::fake([BeelWebhookDeliveryAbandoned::class]);

    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('d1', 'evt-1', 1, false, 5),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    Event::assertNotDispatched(BeelWebhookDeliveryAbandoned::class);
});

it('leaves events alone while BeeL may still be retrying them automatically', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        array_merge(beelDelivery('just-now', 'evt-burst', 1, false, 0), ['delivered_at' => now()->subSeconds(30)->format(DATE_ATOM)]),
        beelDelivery('settled', 'evt-settled', 5, false, 5),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['settled']);
});

it('uses the account and key given as options instead of services.beel', function () {
    config()->set('services.beel.account_id', 'other-account');

    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[beelDelivery('d1', 'evt-1', 1, false, 5)]]]);

    $this->artisan('beel:retry-webhook-deliveries', ['--account-id' => 'acc-1', '--api-key' => 'beel_sk_test_tenant'])
        ->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['d1']);
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/accounts/acc-1/')
        && $request->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
});

it('keeps going when a notification listener throws', function () {
    Event::listen(BeelWebhookSubscriptionInactive::class, fn () => throw new RuntimeException('Slack is down'));

    fakeBeelWebhookApi([beelSubscription('wh-off', active: false), beelSubscription('wh-1')], [
        'wh-1' => [[beelDelivery('d1', 'evt-1', 1, false, 5)]],
    ]);

    $this->artisan('beel:retry-webhook-deliveries')->assertFailed();

    expect(retriedDeliveryIds())->toBe(['d1']);
});

it('keeps going when reading one subscription fails', function () {
    Http::fake(function (ClientRequest $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/webhooks/wh-bad/deliveries') => Http::response(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'no']], 403),
            str_ends_with($path, '/webhooks/wh-1/deliveries') => Http::response(beelPage('deliveries', [beelDelivery('d1', 'evt-1', 1, false, 5)]), 200),
            str_ends_with($path, '/accounts/acc-1/webhooks') => Http::response(beelPage('webhooks', [beelSubscription('wh-bad'), beelSubscription('wh-1')]), 200),
            default => Http::response(['success' => true, 'data' => ['id' => 'x']], 200),
        };
    });

    $this->artisan('beel:retry-webhook-deliveries')
        ->expectsOutputToContain('wh-bad')
        ->assertFailed();

    expect(retriedDeliveryIds())->toBe(['d1']);
});

it('only reports what it would retry in dry-run mode', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('d1', 'evt-1', 1, false, 5),
    ]]]);

    $this->artisan('beel:retry-webhook-deliveries', ['--dry-run' => true])
        ->expectsOutputToContain('evt-1')
        ->assertSuccessful();

    expect(retriedDeliveryIds())->toBe([]);
});

it('skips a subscription BeeL has deactivated and fails so monitoring notices', function () {
    fakeBeelWebhookApi([beelSubscription('wh-off', active: false), beelSubscription('wh-1')], [
        'wh-off' => [[beelDelivery('off-1', 'evt-off', 1, false, 5)]],
        'wh-1' => [[beelDelivery('d1', 'evt-1', 1, false, 5)]],
    ]);

    $this->artisan('beel:retry-webhook-deliveries')
        ->expectsOutputToContain('wh-off')
        ->assertFailed();

    expect(retriedDeliveryIds())->toBe(['d1']);
});

it('logs and dispatches an event for a deactivated subscription so the app can decide how to react', function () {
    Event::fake([BeelWebhookSubscriptionInactive::class]);
    Log::spy();

    $deactivatedAt = now()->subHour()->startOfSecond();
    fakeBeelWebhookApi([array_merge(beelSubscription('wh-off', active: false), [
        'deactivated_by' => 'beel',
        'deactivated_at' => $deactivatedAt->format(DATE_ATOM),
        'consecutive_failures' => 25,
        'last_error' => 'HTTP 401',
    ])], []);

    $this->artisan('beel:retry-webhook-deliveries')->assertFailed();

    Event::assertDispatched(BeelWebhookSubscriptionInactive::class, fn (BeelWebhookSubscriptionInactive $event) => $event->accountId === 'acc-1'
        && $event->subscriptionId === 'wh-off'
        && $event->url === 'https://app.test/beel/webhook'
        && $event->deactivatedBy === 'beel'
        && $event->deactivatedAt?->getTimestamp() === $deactivatedAt->getTimestamp()
        && $event->consecutiveFailures === 25
        && $event->lastError === 'HTTP 401');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'wh-off')
        && $context['deactivated_by'] === 'beel');
});

it('limits the run to the given subscriptions without listing them', function () {
    fakeBeelWebhookApi([], [
        'wh-2' => [[beelDelivery('d2', 'evt-2', 1, false, 5)]],
    ]);

    $this->artisan('beel:retry-webhook-deliveries', ['--webhook-id' => ['wh-2']])->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['d2']);
    Http::assertNotSent(fn (ClientRequest $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/accounts/acc-1/webhooks'));
});

it('reads every page of the delivery history', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [
        [beelDelivery('d-page1', 'evt-1', 2, false, 5)],
        [beelDelivery('d-page2', 'evt-1', 1, true, 6)],
    ]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    // The success on page 2 belongs to the same event, so nothing must be retried.
    expect(retriedDeliveryIds())->toBe([]);
});

it('fails when BeeL rejects a retry but still processes the remaining events', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[
        beelDelivery('d1', 'evt-1', 1, false, 5),
        beelDelivery('d2', 'evt-2', 1, false, 5),
    ]]], retryStatus: 500);

    $this->artisan('beel:retry-webhook-deliveries', [])->assertFailed();

    expect(retriedDeliveryIds())->toHaveCount(2);
});

it('fails with a clear message when no account id is available', function () {
    config()->set('services.beel.account_id', null);
    Http::fake();

    $this->artisan('beel:retry-webhook-deliveries')
        ->expectsOutputToContain('account_id')
        ->assertFailed();

    Http::assertNothingSent();
});

it('schedules itself only when a schedule is configured', function () {
    $commands = fn () => collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'beel:retry-webhook-deliveries'));

    config()->set('beel.webhook_delivery_retry.schedule', null);
    expect($commands())->toBeEmpty();
});

it('registers the configured schedule', function () {
    config()->set('beel.webhook_delivery_retry.schedule', '*/15 * * * *');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'beel:retry-webhook-deliveries'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/15 * * * *');
});

function bindRetryAccounts(array $accounts): void
{
    app()->bind(WebhookRetryAccounts::class, fn () => new class($accounts) implements WebhookRetryAccounts
    {
        public function __construct(private array $accounts) {}

        public function accounts(): iterable
        {
            foreach ($this->accounts as $accountId => $apiKey) {
                yield new AccountCredentials($accountId, $apiKey);
            }
        }
    });
}

/** Two accounts, each with one subscription holding one never-delivered event. `acc-bad` answers 403. */
function fakeTwoAccountWebhookApi(): void
{
    Http::fake(function (ClientRequest $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST') {
            return Http::response(['success' => true, 'data' => ['id' => 'x']], 200);
        }
        if (preg_match('#/accounts/(acc-[a-z0-9]+)/webhooks$#', $path, $m)) {
            return $m[1] === 'acc-bad'
                ? Http::response(['success' => false, 'error' => ['code' => 'FORBIDDEN', 'message' => 'no']], 403)
                : Http::response(beelPage('webhooks', [beelSubscription("wh-{$m[1]}")]), 200);
        }
        if (preg_match('#/webhooks/wh-(acc-[a-z0-9]+)/deliveries$#', $path, $m)) {
            return Http::response(beelPage('deliveries', [beelDelivery("d-{$m[1]}", "evt-{$m[1]}", 1, false, 5)]), 200);
        }

        return Http::response(['success' => false], 404);
    });
}

it('checks every account the bound provider returns, each with its own key', function () {
    bindRetryAccounts(['acc-1' => 'beel_sk_test_one', 'acc-2' => 'beel_sk_test_two']);
    fakeTwoAccountWebhookApi();

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['d-acc-1', 'd-acc-2']);
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '/accounts/acc-1/') && $r->hasHeader('Authorization', 'Bearer beel_sk_test_one'));
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '/accounts/acc-2/') && $r->hasHeader('Authorization', 'Bearer beel_sk_test_two'));
});

it('keeps going with the other accounts when one fails', function () {
    bindRetryAccounts(['acc-bad' => 'beel_sk_test_bad', 'acc-2' => 'beel_sk_test_two']);
    fakeTwoAccountWebhookApi();

    $this->artisan('beel:retry-webhook-deliveries')
        ->expectsOutputToContain('acc-bad')
        ->assertFailed();

    expect(retriedDeliveryIds())->toBe(['d-acc-2']);
});

it('only checks the account given as options, not the provider', function () {
    app()->bind(WebhookRetryAccounts::class, fn () => throw new RuntimeException('The provider must not be used when options are given.'));
    fakeTwoAccountWebhookApi();

    $this->artisan('beel:retry-webhook-deliveries', ['--account-id' => 'acc-2', '--api-key' => 'beel_sk_test_two'])->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['d-acc-2']);
});

it('never applies --webhook-id across the accounts of the provider', function () {
    // Subscription ids belong to one account, so --webhook-id implies a single account.
    app()->bind(WebhookRetryAccounts::class, fn () => throw new RuntimeException('The provider must not be used with --webhook-id.'));
    fakeBeelWebhookApi([], ['wh-2' => [[beelDelivery('d2', 'evt-2', 1, false, 5)]]]);

    $this->artisan('beel:retry-webhook-deliveries', ['--webhook-id' => ['wh-2']])->assertSuccessful();

    expect(retriedDeliveryIds())->toBe(['d2']);
    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/accounts/acc-1/'));
});

it('sends an idempotency key per delivery so overlapping runs never make BeeL redeliver twice', function () {
    fakeBeelWebhookApi([beelSubscription('wh-1')], ['wh-1' => [[beelDelivery('d1', 'evt-1', 1, false, 5)]]]);

    $this->artisan('beel:retry-webhook-deliveries')->assertSuccessful();

    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
        && $request->hasHeader('Idempotency-Key', 'beel-webhook-retry-d1'));
});

it('can restrict the automatic schedule to one server', function () {
    config()->set('beel.webhook_delivery_retry.schedule', '*/15 * * * *');
    config()->set('beel.webhook_delivery_retry.on_one_server', true);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'beel:retry-webhook-deliveries'));

    expect($event->onOneServer)->toBeTrue();
});

it('does not restrict the automatic schedule to one server by default', function () {
    config()->set('beel.webhook_delivery_retry.schedule', '*/15 * * * *');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'beel:retry-webhook-deliveries'));

    expect($event->onOneServer)->toBeFalse();
});
