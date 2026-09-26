# lenorix/laravel-beel

Verified against the package source on 2026-09-26.

## Configuration

`config/services.php`:

```php
'beel' => [
    'key' => env('BEEL_API_KEY'),             // beel_sk_test_... (sandbox) or beel_sk_live_... (production)
    'company_id' => env('BEEL_COMPANY_ID'),   // company UUID, not the NIF
    'account_id' => env('BEEL_ACCOUNT_ID'),   // optional, only for BeelManager::account()
    'base_url' => env('BEEL_BASE_URL', 'https://app.beel.es/api'),
    'webhook_secret' => env('BEEL_WEBHOOK_SECRET'),
],
```

`config/beel.php` (publish with `php artisan vendor:publish --tag="beel-config"`):

| Key | Default | Meaning |
|---|---|---|
| `register_webhook_route` | `true` | Register `POST {webhook_path}` automatically |
| `webhook_path` | `beel/webhook` | Webhook URI; route name `beel.webhook` |
| `webhook_replay_tolerance_seconds` | `300` | Max age of the signed timestamp |
| `webhook_dedupe_seconds` | `900` | Remember accepted event ids; null/0 disables |
| `webhook_dedupe_store` | `null` | Cache store for that (default store if null) |
| `http.timeout` / `http.connect_timeout` | `30` / `10` | Seconds |
| `http.retries` / `http.retry_delay_ms` | `3` / `100` | Laravel retries on connection errors, 429 and 5xx |
| `http.options` | `[]` | Extra Guzzle options |
| `webhook_delivery_retry.max_age_minutes` | `1440` | Only retry events first attempted within this window |
| `webhook_delivery_retry.max_attempts` | `8` | Give up after this many attempts (automatic ones included) |
| `webhook_delivery_retry.schedule` | `null` | Cron expression to auto-schedule `beel:retry-webhook-deliveries` |
| `webhook_delivery_retry.on_one_server` | `false` | Add `onOneServer()` to that schedule (needs a lock-capable cache) |

## BeelManager (singleton) and the `LaravelBeel` facade

- `client(?string $apiKey = null): Lenorix\BeelSdk\Beel` builds a fresh SDK client per call; no state is shared between calls. Throws `InvalidArgumentException` when no key or base URL is available.
- `company(?string $apiKey = null, ?string $companyId = null): BeelCompany` uses `services.beel.company_id` when `$companyId` is null.
- `account(?string $apiKey = null, ?string $accountId = null): BeelAccount` uses `services.beel.account_id` when `$accountId` is null.
- The facade `Lenorix\LaravelBeel\Facades\LaravelBeel` proxies the same three methods.

Default credentials: when an argument is null, `BeelManager` asks the bound `Lenorix\LaravelBeel\Contracts\CredentialsResolver` (`apiKey()`, `accountId()`, `companyId()`, each `?string`). The default `ConfigCredentialsResolver` reads `services.beel.*`. Apps that keep credentials elsewhere (a settings table, the current tenant) bind their own implementation; it is resolved on every call, so it may use per-request state. Explicit arguments always win. The retry command uses the same resolver.

`BeelCompany` and `BeelAccount` are thin decorators over the SDK's `CompanyScope` and `AccountScope`:

- `$company->companyId` / `$account->accountId`, `->scope` (the SDK scope), `->raw` (the generated Jane client for endpoints without a resource wrapper).
- Property access (`$company->invoices`) and method calls (`$company->issuingReadiness()`) are forwarded to the scope; unknown properties throw `LogicException`.
- Company resources: `invoices`, `customers`, `products`, `series`, `recurringInvoices`, `paymentConnections`, `taxConfiguration`, `verifactuConfiguration`.
- Account resources: `companies`, `members`, `invitations`, `webhooks`, `emails`.
- Resources that are not tied to a company or account (`catalogs`, `nif`, `accounts`) are used directly from `client()`: `$beel->nif->validate($nif)`, `$beel->catalogs->taxTypes()`.

## Managing webhook subscriptions (multi-tenant)

`Lenorix\LaravelBeel\BeelWebhookSubscriptions` (resolve from the container):

- `url(?string $webhookKey = null): string` = `APP_URL` + `webhook_path` [+ `/` + rawurlencoded key]; `defaultEvents()` = all `WebhookEventType` values except `PROVISIONER_EVENTS` (`account.claimed`, `company.created`, `representation.signed`).
- `subscribe(callable $store, ?string $webhookKey = null, ?array $events = null, ?string $url = null, ?string $apiKey = null, ?string $accountId = null): BeelWebhookSubscription` creates the subscription and calls `$store($secret)`. Throws `Exceptions\WebhookSubscriptionAlreadyExists` if one already delivers to that URL (trailing slash ignored). If `$store` throws, it deletes the new subscription and rethrows; if the delete fails, `Exceptions\WebhookSubscriptionOrphaned` (`subscriptionId`, previous = store failure).
- `rotate(callable $store, ...)`: BeeL's native rotation (old secret invalid immediately), then `$store($secret)`. `Exceptions\WebhookSubscriptionNotFound` if none; `Exceptions\RotatedWebhookSecretNotStored` (`subscriptionId`, `secret`) if `$store` throws — the secret is only in the property, never the message; persist it from there.
- `find(...): ?BeelWebhookSubscription`, `unsubscribe(...): bool`.
- `BeelWebhookSubscription` has `id`, `url`, `events`, `active`, never the secret. HTTPS URLs only (`InvalidArgumentException` before any API call). Needs `webhooks:read` + `webhooks:write`. Credentials default to the `CredentialsResolver`; pass `apiKey`/`accountId` per tenant.

## Webhook subscription command

`php artisan beel:webhook:subscribe [--url=] [--event=*] [--env-key=BEEL_WEBHOOK_SECRET] [--rotate] [--force]` creates this app's subscription (default URL `APP_URL` + `webhook_path`, HTTPS only; default events all but the provisioner-only `account.claimed`, `company.created`, `representation.signed`) and writes the secret to `.env` in place with a lock (keeping owner, mode and symlinks to a shared `.env`), never printing it. It refuses when a custom `WebhookSecretResolver` is bound or `services.beel.webhook_secret` reads another variable than `--env-key`, since the app wouldn't read the new secret. Before calling BeeL it validates the URL and that `.env` and its directory are writable, and confirms in production. It refuses a second subscription for the same URL (use `--rotate`, which calls `rotateSecret()`). If saving fails after a create it deletes the new subscription; after a rotate the old secret is already dead, so it prints the new one once. Needs `webhooks:write`. It is a thin wrapper over `BeelWebhookSubscriptions` with a `.env` store; single-app only, multi-tenant apps call the service with their own store.

## Diagnosis

`php artisan beel:check` is read-only (GET requests only, no test deliveries) and reports: API key presence and sandbox/live vs `APP_ENV`; `GET /v1/me/identity` (account, environment, and the key's scopes, which that endpoint returns without needing any scope) vs `services.beel.account_id`; the default company's `issuingReadiness()` blockers; missing `webhooks:read`/`webhooks:write`; whether an active HTTPS subscription points at `APP_URL` + `webhook_path` (per-tenant sub-paths count; trailing slashes ignored) and that no two subscriptions share a URL; the webhook secret (default resolver only); and the dedupe store (`array`/`null` error, `file` warning). Errors exit 1, warnings exit 0.

`BeelCompany` forwards `get()`, `update()`, `delete()`, `fiscalSummary()` and `issuingReadiness()` to the SDK scope, and `BeelAccount` forwards `get()`, `usage()`, `changeAccessLevel()`, `createClaimToken()`, `setOwner()` and `endManagement()`; both are annotated with `@method` for IDEs and static analysis.

## Transport, retries and idempotency

- The SDK's own retry layer is disabled (`maxRetries: 0`); Laravel's `PendingRequest::retry` retries connection errors, 429 and 5xx. On a 429 it waits the numeric-seconds `Retry-After` value (capped at 60s, BeeL's rate-limit window), falling back to `retry_delay_ms` when the header is absent or not that form. If retries are exhausted or disabled, handle `BeelRateLimitError::$retryAfterSeconds` yourself for longer waits.
- The SDK adds one `Idempotency-Key` per logical POST before the transport, so Laravel's retries resend the same key. Pass your own key in the `$headers` argument when the operation may be retried across processes or queue attempts.
- All requests go through Laravel's HTTP client, so HTTP client events, `Http::fake()` and global middleware apply.

## Errors

- `Lenorix\BeelSdk\Exception\BeelApiError` (extends `RuntimeException`): `statusCode`, `apiCode`, `details`, `requestId`, `retryAfter`.
- Subclasses: `BeelAuthError` (401/403), `BeelNotFoundError` (404), `BeelConflictError` (409), `BeelValidationError` (422, field errors in `details`), `BeelRateLimitError` (429, `retryAfterSeconds`).
- Transport failures are not `BeelApiError`: `Lenorix\LaravelBeel\LaravelNetworkException` (connection failure, implements PSR-18 `NetworkExceptionInterface`, `getRequest()`) and `Lenorix\LaravelBeel\LaravelClientException` (anything else, PSR-18 `ClientExceptionInterface`).

## Webhook endpoint

Flow of `POST /beel/webhook/{beelWebhookKey?}`:

1. Header pre-filter, before the secret or body is touched: a missing header, no numeric `t`, no `v1` shaped like a lowercase SHA-256 hex digest, or a timestamp outside `webhook_replay_tolerance_seconds` returns `401`.
2. `WebhookSecretResolver::resolve($request)` (default: `services.beel.webhook_secret`). No secret returns `503`, which BeeL retries.
3. `Lenorix\BeelSdk\Webhook\WebhookVerifier::verify()` checks the HMAC over the exact raw body. A mismatch returns a retryable `503` (a header this well-formed but wrong usually means the secret was just rotated, and BeeL invalidates the old one immediately); any other verification failure (malformed JSON body) returns a non-retryable `401`.
4. The decoded payload must have string `id`, string `type` and array `data`, else `400`.
5. Deduplication: the first verified, accepted delivery of an event id claims `Cache::add()` (key = HMAC of the signed payload id with the verifying secret, never the unsigned header or the URL segment, so a captured delivery replayed to another URL isn't re-dispatched and tenants with their own secrets stay apart; TTL = max(`webhook_dedupe_seconds`, 900 by default, 2 × `webhook_replay_tolerance_seconds`), on `webhook_dedupe_store`); a delivery re-accepted under a just-rotated secret is processed again; an unavailable cache store answers 500 (BeeL retries); a redelivery or a simultaneous duplicate gets the same `202` without dispatching again. Failed or unverified requests are never remembered. Why the store matters: the claim must be one atomic check-and-set (a read followed by a write would let two simultaneous deliveries both dispatch) and visible to every process receiving webhooks. Atomic and shared: `redis` (Lua), `memcached` (native add), `database` (insertOrIgnore), `dynamodb` (conditional write). `file` is atomic but per server. `array` (per process, non-atomic) and `null` (stores nothing) make deduplication silently do nothing: set `webhook_dedupe_store` to a proper store if the default is one of them. `on_one_server` for the retry schedule needs the same kind of store (atomic locks).
6. Dispatches `BeelWebhookReceived($id, $type, $data, $payload, $webhookKey)` synchronously, then responds `202`. If a listener throws, it reports the exception, releases the dedupe claim and responds `503`, so BeeL retries. The event also exposes `companyId`, `accountId`, `webhookKey` (the URL segment, nullable) and `isTest()`.

Notes:

- No rate limiting on purpose: BeeL does not retry 4xx responses, so throttling would drop legitimate events. Do not add throttle, auth or CSRF middleware to this route.
- The 503s for a missing secret and for a signature mismatch log a warning (`reason` `secret_missing` / `signature_mismatch`, the webhook key and the unverified `BeeL-Delivery-Id`), at most once per reason per minute; 401s are not logged. Watch for these warnings after rotating a secret.
- The 503-vs-401 split for step 3 is decided by `WebhookVerifier`'s exact exception message, since it carries no error code. If a `lenorix/beel-sdk` update changes that wording, the controller safely falls back to `401`.
- The route skips `TrimStrings` and `ConvertEmptyStringsToNull` so the body is not parsed before verification.
- Listeners run inside the request, before the 202 (BeeL gives up after 10 seconds): keep them light and dispatch queued jobs for real work. A failure to push the job surfaces as a 503 and BeeL retries.
- Lost deliveries: `php artisan beel:retry-webhook-deliveries` groups each subscription's delivery log by `webhook_event_id`, skips events with any successful attempt, and calls `retryDelivery()` on the latest attempt of events first attempted within `beel.webhook_delivery_retry.max_age_minutes` (1440) that have fewer than `max_attempts` (8, automatic attempts included) attempts. Events that reach `max_attempts` are abandoned: it logs a warning and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookDeliveryAbandoned` (`accountId`, `subscriptionId`, `eventId`, `eventType`, `attempts`, `lastDeliveryId`, `lastHttpStatus`, `lastError`, `payload`) on every run while inside the window; listeners should dedupe on `eventId` and recover by re-reading the resource (e.g. `$company->invoices->get($id)`). For deactivated subscriptions (`active: false`, `deactivated_by: beel` after 25 consecutive failures over 48 h) it does not retry; it logs a warning and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookSubscriptionInactive` (`accountId`, `subscriptionId`, `url`, `deactivatedBy`, `deactivatedAt`, `consecutiveFailures`, `lastError`) for the app to notify or react, and exits with failure when it gives up, a retry fails, or a subscription is inactive. It skips events whose latest attempt is under 2 minutes old (BeeL's own retries finish ~75 s after the first attempt). It uses `services.beel.key`/`account_id` (or `--api-key`/`--account-id`); the key must be created with `webhooks:read` (listing) and `webhooks:write` (retrying), since scopes are fixed at key creation. Options `--account-id`, `--api-key`, `--webhook-id=*`, `--max-age`, `--max-attempts`, `--dry-run`; never pass `--api-key` via `Schedule::command()` (it shows in `ps`). By default it checks the single account of the bound `CredentialsResolver`; to check several accounts (e.g. all tenants) bind `Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts` returning `Lenorix\LaravelBeel\AccountCredentials($accountId, $apiKey)` items; a failing account doesn't stop the others. `--account-id`/`--api-key` check only that account; `--webhook-id` also implies a single account (given or default). Listener exceptions are reported without stopping the run. Schedule it with `beel.webhook_delivery_retry.schedule` (cron, null by default) or `Schedule::command(...)`. BeeL keeps only the last 50 delivery logs, so run it frequently enough to see failures. Retries carry `Idempotency-Key: beel-webhook-retry-{deliveryId}` (sent through the generated client, since the SDK's `retryDelivery()` takes no headers), so overlapping runs never redeliver the same attempt twice; a `409 IDEMPOTENCY_KEY_PROCESSING` (another run's retry still in flight) is reported as already being retried, not as a failure.
- Default: the secret is `services.beel.webhook_secret` (bound `ConfigWebhookSecretResolver`); no code needed. Per-tenant secrets: the route is `POST {webhook_path}/{beelWebhookKey?}`; register each tenant's subscription at `route('beel.webhook', ['beelWebhookKey' => $key])` and bind your own `Lenorix\LaravelBeel\Contracts\WebhookSecretResolver` that reads `$request->route('beelWebhookKey')` (return null when it is missing). Never pick the secret from the unverified body (a tenant could sign another tenant's `company_id`). Returning null answers 503. The package never interprets the segment (bare path and any segment behave the same; the resolver decides; the default resolver ignores it). In listeners identify the tenant by `$event->webhookKey`, not by `companyId`/`accountId`, and ignore events whose ids don't belong to it; this is only trustworthy when the resolver returns a distinct secret per key and null for unknown or missing keys. A request-bound `CredentialsResolver` returns null in queued listeners and scheduled commands: pass explicit credentials there and bind `WebhookRetryAccounts`.
- To own the endpoint entirely, set `register_webhook_route` to `false` and use `WebhookVerifier` directly with the raw body (`$request->getContent()`).

Listener and job example (the listener only routes; the job does the work):

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Lenorix\BeelSdk\Webhook\WebhookEventType;
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

class RouteBeelWebhook
{
    public function handle(BeelWebhookReceived $event): void
    {
        if ($event->isTest()) {
            return; // dashboard test delivery
        }

        if ($event->type === WebhookEventType::VERIFACTU_STATUS_UPDATED->value) {
            SyncVerifactuStatus::dispatch($event->id, $event->companyId, $event->data);
        }
    }
}

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
        DB::transaction(function () {
            // Claim the event before doing anything. With a unique index on event_id, a concurrent
            // job for a redelivery waits on the index and then inserts nothing.
            $claimed = DB::table('processed_beel_events')->insertOrIgnore([
                'event_id' => $this->eventId,
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return; // already processed
            }

            // ... update the invoice's VERI*FACTU status from $this->data
        });
    }
}
```

`processed_beel_events` (with a unique index on `event_id`) is app code. If the work throws, the transaction rolls back the claim too, so the job's retry processes the event again. That makes the database changes exactly-once, but external side effects inside the transaction (emails, HTTP calls) can still repeat after a rollback: make them idempotent as well, or dispatch them after commit.

## Testing

- `Http::preventStrayRequests()` in the base test case and `Http::fake([...])` per test. Fake BeeL's envelope: `Http::response(['data' => [...]], 200)`.
- Assert outgoing calls with `Http::assertSent(fn (Illuminate\Http\Client\Request $r) => $r->hasHeader('Authorization', 'Bearer ...') && str_contains($r->url(), $companyId))`.
- Simulate transport failures with `Http::fake(fn () => throw new GuzzleHttp\Exception\ConnectException('...', new GuzzleHttp\Psr7\Request('GET', 'https://example.test')))`.
- Signed webhook requests: use the package's test helpers instead of signing by hand.

```php
use Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks;
use Lenorix\LaravelBeel\Testing\WebhookSignature;

uses(InteractsWithBeelWebhooks::class);

// Signed with services.beel.webhook_secret, fresh event id per call (so the package's dedupe
// doesn't swallow a second post); $overrides sets envelope fields; optional webhook key and secret.
$this->postBeelWebhook('invoice.issued', ['invoice_id' => 'inv_1'], ['company_id' => $companyId])->assertStatus(202);

// Hand-built request: sign the exact raw body you send.
$header = WebhookSignature::sign($rawBody, $secret);
```
