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

### Where default credentials come from

By default (no extra setup), the API key, company id and account id come from `config/services.php` (`services.beel.key`, `company_id`, `account_id`), read by the bound `Lenorix\LaravelBeel\Contracts\CredentialsResolver` (`ConfigCredentialsResolver`). Explicit arguments always win over those defaults.

This is extensible: to keep the defaults somewhere else, such as a settings table or the current tenant, bind your own resolver and every `client()`, `company()` and `account()` call without explicit arguments uses it (so does `beel:retry-webhook-deliveries`):

```php
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;

class TenantBeelCredentials implements CredentialsResolver
{
    public function apiKey(): ?string
    {
        return tenant()?->beel_api_key;
    }

    public function accountId(): ?string
    {
        return tenant()?->beel_account_id;
    }

    public function companyId(): ?string
    {
        return tenant()?->beel_company_id;
    }
}

// In a service provider's register():
$this->app->bind(CredentialsResolver::class, TenantBeelCredentials::class);
```

The resolver is resolved from the container on every call, so it may read per-request state (`tenant()` here stands for your app's own tenant lookup). Returning `null` means "not available": the call then fails with a clear error unless that value is passed explicitly.

Account-level resources (members, invitations, managed companies, account webhooks, email delivery history) work the same way, through `account()`:

```php
$account = app(BeelManager::class)->account();
$account->members->list();
```

`client()`'s unscoped resources — `catalogs` (shared catalogs), `nif` (AEAT NIF validation), and `accounts` (listing/provisioning accounts) — aren't tied to a company or account UUID, so there's no wrapper for them; use them directly off the client returned by `client()`, e.g. `$beel->catalogs->taxTypes()`.

## Checking your setup

`php artisan beel:check` diagnoses the configuration in one go. It is read-only: it only sends GET requests to BeeL and never changes anything there (no test deliveries either). It reports:

- whether an API key is available, and whether a sandbox key runs in production or a live key outside it;
- whether BeeL accepts the key, which account and environment it belongs to, and whether that matches `services.beel.account_id`;
- whether the default company can issue invoices (`issuingReadiness()`), listing the blockers if not;
- whether the key has the `webhooks:read` / `webhooks:write` scopes the retry command needs (read from BeeL's identity endpoint, no write attempted);
- whether a BeeL webhook subscription points at this app (`APP_URL` + the webhook path; per-tenant URLs under it count), is active, and uses HTTPS;
- whether a webhook secret is configured (with the default resolver);
- whether the webhook dedupe cache store is usable: `array`/`null` is an error, `file` a warning.

Errors exit with status 1; warnings are reported but exit 0.

## Laravel HTTP client

Requests pass through Laravel's HTTP client, so Laravel HTTP events and configured Guzzle options are available. Configure `timeout`, `connect_timeout`, `retries`, `retry_delay_ms`, and optional Guzzle `options` in `config/beel.php`. Retries apply to connection errors, HTTP 429, and 5xx responses; a 429's `Retry-After` (capped at 60s) is honored when present, falling back to `retry_delay_ms` otherwise.

## Webhooks

The simplest setup needs no code: run `php artisan beel:webhook:subscribe` (or create the subscription in BeeL yourself, pointing at `https://your-app/beel/webhook`, and put its signing secret in `BEEL_WEBHOOK_SECRET`, read as `services.beel.webhook_secret`), then listen to `BeelWebhookReceived`. Everything below that (per-tenant secrets, your own endpoint) is optional.

By default, the package registers a POST route at `/beel/webhook`, verifies the exact raw request body against the `BeeL-Signature` HMAC header (rejecting signatures older than `beel.webhook_replay_tolerance_seconds`, 300 by default), and responds with 202. Requests that can't possibly be from BeeL (missing or malformed signature header, timestamp outside the window) are rejected with 401 from the header alone, before the secret is resolved or the body is read, and the route skips Laravel's `TrimStrings`/`ConvertEmptyStringsToNull` so the body is never parsed before verification. A well-formed header whose HMAC doesn't match the configured secret — typically a secret rotated moments ago, since BeeL invalidates the old one immediately — gets a retryable 503 instead of 401, so BeeL's redelivery (5 attempts over roughly 75s) covers the deploy window; a malformed JSON body still gets a non-retryable 401. Those 503s (signature mismatch, or no secret configured) also log a warning, at most once per reason per minute so forged requests can't flood the log, and never including the secret, the signature or the body; 401s are not logged. The route is deliberately not rate limited: BeeL does not retry deliveries answered with a 4xx, so a throttled burst of legitimate events would be lost. Once the signature is verified, `BeelWebhookReceived` is dispatched **before** answering, so BeeL's 202 means your listeners ran. If a listener throws, the exception is reported and the webhook answers 503: BeeL retries the delivery (and `beel:retry-webhook-deliveries` sees it as failed) instead of the event being lost behind a 202. The event provides the event `id`, its `type`, its `data`, the complete `payload`, the `companyId` and `accountId` it belongs to (when the event type carries them), the `webhookKey` URL segment it arrived on (null on the bare path), and `isTest()` (true only for test deliveries triggered from the BeeL dashboard).

**Keep listeners light and move heavy work to queued jobs.** Listeners run inside the webhook request, before the 202: slow work delays BeeL's response (BeeL gives up on a delivery after 10 seconds and retries it), and any failure makes BeeL redeliver the whole event. A listener should only filter, route and dispatch a job; the job does the real work with its own retries, backoff and failure handling.

```php
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

Event::listen(BeelWebhookReceived::class, function (BeelWebhookReceived $event): void {
    // $event->id, $event->type, $event->data, $event->payload, $event->companyId, $event->accountId, $event->webhookKey, $event->isTest()
});
```

BeeL may redeliver the same event (e.g. if a prior delivery timed out); every delivery of an event carries the same id, also sent as its `Idempotency-Key` header. The package remembers accepted events for `webhook_dedupe_seconds` (15 minutes by default) in the cache: a redelivery within that window gets the same 202 and does not dispatch `BeelWebhookReceived` again, and two simultaneous deliveries of the same event dispatch it only once, because the claim uses the atomic `Cache::add()`. Details:

- Only verified, accepted (202) deliveries are remembered, so an unverified request can't block a real event and a 503 (e.g. wrong secret, or a listener that failed) is still retried by BeeL: a failed listener releases the claim.
- The claim is keyed on the signed payload id and the secret that verified it, never on the unsigned `Idempotency-Key` header or the URL segment (the signature doesn't cover the URL): replaying a captured delivery to another URL doesn't dispatch it again, and since each BeeL subscription has its own secret, one tenant can't swallow another tenant's event. Side effect: an event accepted under an old secret and redelivered under a just-rotated one is processed again, which the listener's own deduplication covers.
- The claim lasts at least twice `webhook_replay_tolerance_seconds` (a signature stays valid for that span, since the check is `|now - t| <= tolerance`), even if `webhook_dedupe_seconds` is lower, so a captured delivery can't be replayed after its claim expires. Disabling deduplication reopens that replay window.
- If the dedupe cache store is unavailable, the webhook answers 500 without dispatching, and BeeL retries it.
- `webhook_dedupe_store` picks the cache store (default store if `null`). See *Cache store requirements* below.
- Set `webhook_dedupe_seconds` to `null` or `0` to disable it.

#### Cache store requirements

The deduplication is a check-and-claim that must happen in one indivisible step: "claim this event id only if nobody has claimed it yet". If it were a read followed by a write, two simultaneous deliveries of the same event could both read "not claimed" before either writes, and both would dispatch `BeelWebhookReceived`. It must also be visible to every process that receives webhooks (all PHP-FPM workers, Octane workers and servers), otherwise each process only sees its own claims.

| Cache store | Atomic claim | Shared between servers | Use it for webhook deduplication |
|---|---|---|---|
| `redis` | Yes (Lua script) | Yes | Recommended |
| `memcached` | Yes (native `add`) | Yes | Recommended |
| `database` | Yes (`insertOrIgnore` on the unique key) | Yes | Recommended |
| `dynamodb` | Yes (conditional write) | Yes | Recommended |
| `file` | Yes (exclusive file lock) | No, one server only | Only if a single server receives webhooks |
| `array` | No, and its memory lives per process | No | Never: deduplication silently does nothing |
| `null` | Stores nothing | No | Never: deduplication silently does nothing |

If your default cache store is `array` or `null` (common in tests or minimal setups), set `webhook_dedupe_store` to one of the recommended stores. The same kind of store is needed for `on_one_server` on the retry command schedule, which relies on atomic cache locks.

Listeners that aren't naturally idempotent should still deduplicate using `$event->id` (for example with a unique index, as below), since the cache window is short and cache entries can be evicted.

For example, a listener that hands the work to a job:

```php
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;
use Lenorix\BeelSdk\Webhook\WebhookEventType;

class RouteBeelWebhook
{
    public function handle(BeelWebhookReceived $event): void
    {
        // Skip test deliveries so they never touch production side effects.
        if ($event->isTest()) {
            return;
        }

        match ($event->type) {
            WebhookEventType::VERIFACTU_STATUS_UPDATED->value => SyncVerifactuStatus::dispatch($event->id, $event->companyId, $event->data),
            default => null,
        };
    }
}
```

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncVerifactuStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public string $eventId, public ?string $companyId, public array $data) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(): void
    {
        // Idempotent processing: deduplicate durably on $this->eventId (e.g. a unique index).
    }
}
```

If pushing the job fails (for example the queue backend is down), the listener throws, the webhook answers 503 and BeeL retries later, so nothing is lost.

### Recovering deliveries that never arrived

BeeL retries a failed delivery only 5 times over about 75 seconds, and not at all after a 4xx. As a safety net, `php artisan beel:retry-webhook-deliveries` reads each subscription's delivery history and asks BeeL to redeliver every event with no successful attempt, so the event goes through the normal verified endpoint again and BeeL records the delivery:

- Events with any successful attempt are skipped; attempts are grouped by BeeL's event id.
- Only events first attempted within `max_age_minutes` (default 24 h) are retried, and not while their latest attempt is under 2 minutes old, so a run never races BeeL's own automatic retries (which finish about 75 s after the first attempt).
- Events that already have `max_attempts` attempts (default 8, BeeL's automatic ones included) are given up: the app never received them, so the command logs a warning and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookDeliveryAbandoned` (`accountId`, `subscriptionId`, `eventId`, `eventType`, `attempts`, `lastDeliveryId`, `lastHttpStatus`, `lastError`, and the `payload` BeeL tried to send) so the app can alert someone or re-read the affected resource from the API. It is dispatched on every run while the event is inside the retry window, so deduplicate notifications on `eventId`.
- Subscriptions BeeL has deactivated (it pauses them after 25 consecutive failures over more than 48 hours) are not retried: the command logs a warning and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookSubscriptionInactive` (`accountId`, `subscriptionId`, `url`, `deactivatedBy`, `deactivatedAt`, `consecutiveFailures`, `lastError`), so the app decides how to notify or react.
- The command exits with a failure code when something was given up, a retry was rejected, or a subscription is inactive, so the scheduler or your monitoring notices.

**If you use this command, create the API key with the `webhooks:read` and `webhooks:write` scopes**: listing subscriptions and deliveries needs the first, asking BeeL to retry needs the second. BeeL fixes a key's scopes at creation, so a key without them answers 403.

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

Either way Laravel's scheduler (`schedule:run`) must be running. Each retry request carries an `Idempotency-Key` derived from the delivery attempt, so overlapping runs (a manual run, or several servers running the scheduler) never make BeeL redeliver the same attempt twice; a run that finds another run's retry still in flight reports it as already being retried, not as a failure. To also avoid the duplicate work, set `'on_one_server' => true` under `webhook_delivery_retry` so the automatic schedule runs on a single server; it needs a cache store with atomic locks (Redis, Memcached, database, ...). With your own `Schedule::command()`, add `->onOneServer()` yourself.

By default the command checks one account: the one from the bound `CredentialsResolver` (`services.beel.account_id` and `services.beel.key` unless you bound your own). To check several accounts, for example every tenant in your database, bind `Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts`; the scheduled run then goes through all of them, each with its own key, and a failing account doesn't stop the rest:

```php
use Lenorix\LaravelBeel\AccountCredentials;
use Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts;

class TenantWebhookRetryAccounts implements WebhookRetryAccounts
{
    public function accounts(): iterable
    {
        foreach (Tenant::whereNotNull('beel_account_id')->cursor() as $tenant) {
            yield new AccountCredentials($tenant->beel_account_id, $tenant->beel_api_key);
        }
    }
}

// In a service provider's register():
$this->app->bind(WebhookRetryAccounts::class, TenantWebhookRetryAccounts::class);
```

`--account-id` / `--api-key` check just that one account instead, for one-off runs. `--webhook-id` also limits the run to a single account (the given one, or the default), since subscription ids belong to one account. Don't pass `--api-key` through `Schedule::command()`: it would show in `ps` and `schedule:list`; bind `WebhookRetryAccounts` instead.

### Creating the subscription

`php artisan beel:webhook:subscribe` creates this app's BeeL webhook subscription and writes its signing secret to `.env` (`BEEL_WEBHOOK_SECRET`, or `--env-key=`); it never prints the secret. BeeL shows a secret only once, so the command:

- checks the URL (`APP_URL` + the webhook path, or `--url=`; it must be HTTPS) and that `.env` is writable before calling BeeL, and asks for confirmation in production (`--force` skips it);
- refuses to create a second subscription for the same URL (it would sign with a different secret the app can't verify); `--rotate` replaces the existing subscription's secret instead;
- writes `.env` atomically, keeping every other line; if it still can't save a newly created subscription's secret, it deletes that subscription so nothing is left half-configured. After `--rotate` the old secret is already invalid, so in that one case it prints the new secret once as the only way to recover.

It subscribes every event except the provisioner-only ones (`--event=` to choose), needs the `webhooks:write` scope, and reminds you to re-run `config:cache` and restart Octane, queue workers or Horizon. BeeL sends a test delivery while creating the subscription, before the secret is saved, so that first one is expected to fail. The command is for a single app; multi-tenant apps create each tenant's subscription with `$account->webhooks->create()` and store its secret per tenant.

### One secret per tenant (optional)

By default the secret comes from `services.beel.webhook_secret`, read by the bound `Lenorix\LaravelBeel\Contracts\WebhookSecretResolver` (`ConfigWebhookSecretResolver`). If several BeeL accounts or tenants send webhooks to the same app, each subscription has its own secret. The route accepts an optional trailing segment, `/beel/webhook/{beelWebhookKey}`, so each subscription can point at its own URL. The package never interprets that segment: the controller treats `/beel/webhook` and `/beel/webhook/{anything}` the same and lets the resolver decide. The default resolver ignores it, so both URLs use the config secret. A per-tenant resolver picks the secret from it:

```php
use Illuminate\Http\Request;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;

class TenantWebhookSecrets implements WebhookSecretResolver
{
    public function resolve(Request $request): ?string
    {
        $key = $request->route('beelWebhookKey');
        if (! is_string($key) || $key === '') {
            return null; // bare /beel/webhook: no tenant, never match a tenant with a null key
        }

        return Tenant::where('webhook_key', $key)->value('beel_webhook_secret');
    }
}

// In a service provider's register():
$this->app->bind(WebhookSecretResolver::class, TenantWebhookSecrets::class);
```

Build each tenant's URL with `route('beel.webhook', ['beelWebhookKey' => $tenant->webhook_key])` when creating its BeeL subscription. Returning `null` answers 503 (BeeL retries), and a signature that doesn't match the secret you return answers 503 as well. `/beel/webhook` without a segment keeps working, with `$request->route('beelWebhookKey')` being `null`.

Pick the secret only from the URL or other request metadata you control, never from the unverified payload: a tenant who knows its own secret could sign a payload carrying another tenant's `company_id` or `account_id`. For the same reason, **your listeners must identify the tenant by `$event->webhookKey`** (the URL segment the delivery arrived on), not by `$event->companyId` or `$event->accountId`, and should ignore events whose `companyId`/`accountId` don't belong to that tenant. This holds only if your resolver returns a distinct secret per key and `null` for unknown or missing keys, as above: with the default resolver, or one that falls back to a shared secret, any segment is accepted with that secret and `webhookKey` is just a label, not a verified tenant.

A tenant-aware `CredentialsResolver` (one that reads the current request or authenticated tenant) returns `null` in queued listeners and scheduled commands, where there is no request. Queued webhook listeners should pass explicit credentials looked up from `$event->webhookKey`, and such apps must also bind `WebhookRetryAccounts` so the scheduled retry command knows which accounts to check.

Disable the automatic route with `register_webhook_route => false` to register an application-owned endpoint. You can still use the SDK's `WebhookVerifier` directly.

## Testing your integration

Never let tests reach the real BeeL API: all SDK traffic goes through Laravel's HTTP client, so `Http::fake()` and `Http::preventStrayRequests()` cover it.

To test your webhook listeners end to end, use the `InteractsWithBeelWebhooks` trait. It posts a correctly signed delivery (a fresh event id per call, the exact bytes it signs) to the package's endpoint:

```php
use Illuminate\Support\Facades\Queue;
use Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks;

uses(InteractsWithBeelWebhooks::class); // or `use InteractsWithBeelWebhooks;` in a PHPUnit TestCase

it('queues a VERI*FACTU sync when BeeL reports a status change', function () {
    Queue::fake();

    $this->postBeelWebhook('verifactu.status.updated', ['new_status' => 'ACCEPTED'], ['company_id' => 'company-uuid'])
        ->assertStatus(202);

    Queue::assertPushed(SyncVerifactuStatus::class);
});
```

`postBeelWebhook($type, $data, $overrides, $webhookKey, $secret)` signs with `services.beel.webhook_secret` unless you pass a secret; `$overrides` sets envelope fields such as `id`, `company_id` or `test`. For hand-built requests, `Lenorix\LaravelBeel\Testing\WebhookSignature::sign($rawBody, $secret)` returns the `BeeL-Signature` header value for the exact body you send.

## AI guidelines (Laravel Boost)

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines and a `beel-invoicing` skill for AI coding agents. They cover this package, the `lenorix/beel-sdk` API (which, as a transitive dependency, can't ship its own), BeeL's API behaviour, and the VERI*FACTU rules an app still has to respect, plus what is out of scope (B2B e-invoicing, TicketBAI, SII). Run `php artisan boost:install` (or `boost:update --discover` if Boost is already installed) to pick them up.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `lenorix/beel-sdk` 0.2+

## License

MIT. See [LICENSE.md](LICENSE.md).
