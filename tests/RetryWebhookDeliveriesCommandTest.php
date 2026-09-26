<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    fakeBeelWebhookApi([beelSubscription('wh-off', active: false) + [
        'deactivated_by' => 'beel',
        'deactivated_at' => $deactivatedAt->format(DATE_ATOM),
        'consecutive_failures' => 25,
        'last_error' => 'HTTP 401',
    ]], []);

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
