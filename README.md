# Laravel BeeL

Laravel integration for the [`lenorix/beel-sdk`](https://github.com/lenorix/BeeL-php-sdk) client. The SDK handles the BeeL API itself; this package wires it into Laravel with config, the Laravel HTTP client, clients built with credentials resolved at runtime (not just from config), and webhook events.

## Installation

```bash
composer require lenorix/laravel-beel
```

Add the BeeL API settings to `config/services.php`:

```php
'beel' => [
    'key' => env('BEEL_API_KEY'),
    'company_id' => env('BEEL_COMPANY_ID'),
    // Optional: only needed if you use BeelManager::account().
    'account_id' => env('BEEL_ACCOUNT_ID'),
    'base_url' => env('BEEL_BASE_URL', 'https://app.beel.es/api'),
    'webhook_secret' => env('BEEL_WEBHOOK_SECRET'),
],
```

Publish the integration options if you need to change the webhook route or HTTP settings:

```bash
php artisan vendor:publish --tag="beel-config"
```

The config file is `config/beel.php`. It controls automatic route registration and Laravel HTTP timeouts/retries. Laravel owns retries in this integration; the SDK retry layer is disabled to avoid stacking retries, and the SDK continues to generate `Idempotency-Key` headers for POST requests.

## Configure and use the client

The BeeL API key, company UUID, and base URL are application service settings. Resolve `BeelManager` from the container to create a normal SDK client:

```php
use Lenorix\LaravelBeel\BeelManager;

$beel = app(BeelManager::class)->client();
$rawClient = $beel->raw;
```

The manager can also create a company scope using the configured `company_id`:

```php
$company = app(BeelManager::class)->company();
$invoice = $company->invoices->create($request);
```

Or pass a UUID explicitly:

```php
$company = $beel->company(config('services.beel.company_id'));
$invoice = $company->invoices->create($request);
```

`LaravelBeel` is also available as a facade to the same manager, if preferred:

```php
$beel = LaravelBeel::client();
```

The manager creates a fresh SDK client each time `client()` is called and does not retain request state. An API key can optionally override the configured key for a particular client:

```php
$beel = app(BeelManager::class)->client(apiKey: $customApiKey);
```

Company and account scopes expose the SDK's resource objects directly, plus `scope` (the original SDK scope) and `raw` (the generated client for endpoints not covered by resource wrappers). `company->invoices->getPdf($id)` returns the SDK's PDF response, including its temporary download URL.

Both `client()` and `company()` also accept credentials resolved at runtime instead of read from config — useful for multi-tenant apps, but not limited to that case:

```php
$company = app(BeelManager::class)->company(
    apiKey: $tenant->beel_api_key,
    companyId: $tenant->beel_company_id,
);
```

Account-level resources (members, invitations, managed companies, account webhooks, email delivery history) work the same way, through `account()`:

```php
$account = app(BeelManager::class)->account();
$account->members->list();
```

`client()`'s unscoped resources — `catalogs` (shared catalogs), `nif` (AEAT NIF validation), and `accounts` (listing/provisioning accounts) — aren't tied to a company or account UUID, so there's no wrapper for them; use them directly off the client returned by `client()`, e.g. `$beel->catalogs->taxTypes()`.

## Laravel HTTP client

Requests pass through Laravel's HTTP client, so Laravel HTTP events and configured Guzzle options are available. Configure `timeout`, `connect_timeout`, `retries`, `retry_delay_ms`, and optional Guzzle `options` in `config/beel.php`. Retries apply to connection errors, HTTP 429, and 5xx responses.

## Webhooks

By default, the package registers a POST route at `/beel/webhook`, verifies the exact raw request body against the `BeeL-Signature` HMAC header (rejecting signatures older than `beel.webhook_replay_tolerance_seconds`, 300 by default), and responds with 202. Requests that can't possibly be from BeeL (missing or malformed signature header, timestamp outside the window) are rejected with 401 from the header alone, before the secret is resolved or the body is read, and the route skips Laravel's `TrimStrings`/`ConvertEmptyStringsToNull` so the body is never parsed before verification. A well-formed header whose HMAC doesn't match the configured secret — typically a secret rotated moments ago, since BeeL invalidates the old one immediately — gets a retryable 503 instead of 401, so BeeL's redelivery (5 attempts over roughly 75s) covers the deploy window; a malformed JSON body still gets a non-retryable 401. The route is deliberately not rate limited: BeeL does not retry deliveries answered with a 4xx, so a throttled burst of legitimate events would be lost. Once the signature is verified, `BeelWebhookReceived` is dispatched via [`defer()`](https://laravel.com/docs/12.x/helpers#method-defer), so it runs after the 202 response has already been sent back to BeeL and never adds listener latency to the webhook round-trip. The event provides the event `id`, its `type`, its `data`, the complete `payload`, the `companyId` it belongs to (when the event type carries one), and `isTest()` (true only for test deliveries triggered from the BeeL dashboard); listeners that need to survive a worker restart or guarantee delivery under load should still implement `ShouldQueue`, since `defer()` only protects response latency, not delivery.

```php
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

Event::listen(BeelWebhookReceived::class, function (BeelWebhookReceived $event): void {
    // $event->id, $event->type, $event->data, $event->payload, $event->companyId, $event->isTest()
});
```

BeeL may redeliver the same event (e.g. if a prior delivery timed out), so listeners that aren't naturally idempotent should deduplicate using `$event->id` — for example, skip processing if that id was already recorded, before doing any real work.

For anything beyond trivial processing, implement the listener as a queued class instead of a closure, so it gets real retries (with your own backoff and failure handling) independent of whether BeeL happens to redeliver:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

class ProcessBeelWebhook implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(BeelWebhookReceived $event): void
    {
        // Skip (or route to a separate handler) test events so they never touch production side effects.
        if ($event->isTest()) {
            return;
        }

        // ... your idempotent processing for $event->type / $event->data
    }
}
```

### Recovering deliveries that never arrived

BeeL retries a failed delivery only 5 times over about 75 seconds, and not at all after a 4xx. As a safety net, `php artisan beel:retry-webhook-deliveries` reads each subscription's delivery history and asks BeeL to redeliver every event with no successful attempt, so the event goes through the normal verified endpoint again and BeeL records the delivery:

- Events with any successful attempt are skipped; attempts are grouped by BeeL's event id.
- Only events first attempted within `max_age_minutes` (default 24 h) are retried, and not while their latest attempt is under 2 minutes old, so a run never races BeeL's own automatic retries (which finish about 75 s after the first attempt).
- Events that already have `max_attempts` attempts (default 8, BeeL's automatic ones included) are given up: the app never received them, so the command logs a warning and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookDeliveryAbandoned` (`accountId`, `subscriptionId`, `eventId`, `eventType`, `attempts`, `lastDeliveryId`, `lastHttpStatus`, `lastError`, and the `payload` BeeL tried to send) so the app can alert someone or re-read the affected resource from the API. It is dispatched on every run while the event is inside the retry window, so deduplicate notifications on `eventId`.
- Subscriptions BeeL has deactivated (it pauses them after 25 consecutive failures over more than 48 hours) are not retried: the command logs a warning and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookSubscriptionInactive` (`accountId`, `subscriptionId`, `url`, `deactivatedBy`, `deactivatedAt`, `consecutiveFailures`, `lastError`), so the app decides how to notify or react.
- The command exits with a failure code when something was given up, a retry was rejected, or a subscription is inactive, so the scheduler or your monitoring notices.

Credentials: listing subscriptions and deliveries needs the `webhooks:read` scope and retrying needs `webhooks:write`. To keep `webhooks:write` off the app's main key, set a dedicated `api_key` (and `account_id`) under `webhook_delivery_retry` in the published `config/beel.php`, for example with `env('BEEL_WEBHOOK_RETRY_API_KEY')`; otherwise it uses `services.beel.key` and `services.beel.account_id`.

Options: `--account-id=`, `--api-key=`, `--webhook-id=` (repeatable), `--max-age=` (minutes), `--max-attempts=`, `--dry-run`.

Both events are dispatched on every run while the condition persists, so deduplicate or throttle notifications (on `eventId` / `subscriptionId`). A listener that throws is reported and does not stop the run; an API error on one subscription is reported and the others are still processed.

To run it periodically, either set a cron expression in `config/beel.php`:

```php
'webhook_delivery_retry' => [
    'schedule' => '*/15 * * * *',
],
```

or schedule it yourself, for example in `routes/console.php`:

```php
Schedule::command('beel:retry-webhook-deliveries')->everyFifteenMinutes()->withoutOverlapping();
```

Either way Laravel's scheduler (`schedule:run`) must be running. For several accounts with their own credentials, run it in-process so API keys never appear on a command line (visible in `ps` and `schedule:list`):

```php
use Illuminate\Support\Facades\Artisan;

Schedule::call(function () {
    foreach (Tenant::all() as $tenant) {
        Artisan::call('beel:retry-webhook-deliveries', [
            '--account-id' => $tenant->beel_account_id,
            '--api-key' => $tenant->beel_webhooks_api_key,
        ]);
    }
})->everyFifteenMinutes()->withoutOverlapping();
```

Never pass `--api-key` through `Schedule::command()`.

Disable the automatic route with `register_webhook_route => false` to register an application-owned endpoint. You can still use the SDK's `WebhookVerifier` directly. For tenant-specific secrets, replace the `WebhookSecretResolver` binding and resolve the secret from trusted request metadata (such as a route identifier or known endpoint) before verifying the body. Do not select a secret based on unverified payload contents.

## AI guidelines (Laravel Boost)

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines and a `beel-invoicing` skill for AI coding agents. They cover this package, the `lenorix/beel-sdk` API (which, as a transitive dependency, can't ship its own), BeeL's API behaviour, and the VERI*FACTU rules an app still has to respect, plus what is out of scope (B2B e-invoicing, TicketBAI, SII). Run `php artisan boost:install` (or `boost:update --discover` if Boost is already installed) to pick them up.

## Requirements

- PHP 8.4+
- Laravel 11.23+, 12, or 13 (webhook processing uses `defer()`, added in Laravel 11.23)
- `lenorix/beel-sdk` 0.2+

## License

MIT. See [LICENSE.md](LICENSE.md).
