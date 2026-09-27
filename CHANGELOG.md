# Changelog

All notable changes to `laravel-beel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release. Requires PHP 8.4+, Laravel 13, and `lenorix/beel-sdk` 0.6+.

### Added

- `BeelManager` (and the `LaravelBeel` facade) to build `lenorix/beel-sdk` clients: `client()`, `company()` returning `BeelCompany`, and `account()` returning `BeelAccount`. Both scopes expose the SDK's resources plus `scope` and `raw`.
- Credentials from `config/services.php` (`services.beel.key`, `company_id`, `account_id`) by default. Explicit arguments always win. Bind `Contracts\CredentialsResolver` to take the defaults from anywhere else, such as a settings table or the current tenant; it is resolved on every call.
- Reported exceptions that wrap a BeeL error get its `context()` (`request_id`, `api_code`, `status_code`, `retry_after`; never `details`, which can echo submitted NIFs or amounts) in their log context, as Laravel already does for the BeeL error itself.
- All SDK traffic goes through Laravel's HTTP client, so HTTP events, `Http::fake()` and Guzzle options apply. Configurable timeouts. Retries are delegated to `lenorix/beel-sdk` (`beel.http.retries`, `retry_delay_ms`, `max_retry_delay_ms`): 429s, 5xx and connection errors, only for requests safe to repeat, waiting what BeeL asks up to 60 s and throwing `BeelRateLimitError` with `retryAfterSeconds` beyond that.
- Webhook endpoint `POST /beel/webhook/{beelWebhookKey?}` that verifies the `BeeL-Signature` HMAC against the raw body and answers 202:
  - It dispatches `Events\BeelWebhookReceived` before answering (a failing listener answers 503 so BeeL retries), with `id`, `type`, `data`, `payload`, `companyId`, `accountId`, `webhookKey`, `isTest()` and `typed()` (the SDK's typed `WebhookEvent`, built lazily)`.
  - The secret comes from `services.beel.webhook_secret` by default. Bind `Contracts\WebhookSecretResolver` for one secret per tenant, chosen from the optional URL segment.
  - Answers:
    - 401: implausible signature headers, rejected from the header alone before resolving the secret or reading the body.
    - 503 (retryable): a well-formed signature that doesn't match, e.g. just after a secret rotation, or no secret configured.
    - 400: a verified payload without `id`, `type` and `data`.
  - The 503s for a missing secret or a signature mismatch log a throttled warning (never the secret, signature or body).
  - Accepted event ids are remembered in the cache for `webhook_dedupe_seconds` (15 minutes, and at least twice the replay tolerance; configurable store), so a redelivery, a simultaneous duplicate or a replay to another URL gets the same 202 without dispatching the event again. Only verified deliveries are remembered, keyed on the event id and the verifying secret.
  - The replay tolerance is configurable (`webhook_replay_tolerance_seconds`, 300 s by default). The route skips `TrimStrings` and `ConvertEmptyStringsToNull` and is not rate limited, because BeeL never retries a 4xx.
- `php artisan beel:retry-webhook-deliveries`, a safety net for webhooks that never arrived:
  - It asks BeeL to redeliver events with no successful attempt that fall within `max_age_minutes`. Events whose latest attempt is under 2 minutes old are left for BeeL's automatic retries, and events stop being retried at `max_attempts`.
  - It logs and dispatches `Events\BeelWebhookDeliveryAbandoned` when it gives up on an event, and `Events\BeelWebhookSubscriptionInactive` for subscriptions BeeL has deactivated. It exits with failure so monitoring notices.
  - Retries carry a per-attempt `Idempotency-Key`, so overlapping runs never redeliver twice (a retry still in flight in another run is not a failure).
  - Checks one account by default. Bind `Contracts\WebhookRetryAccounts` to check several, each with its own key.
  - Optional automatic scheduling (`webhook_delivery_retry.schedule`, plus `on_one_server`).
  - The API key needs the `webhooks:read` and `webhooks:write` scopes.
- `$company->invoices->storePdf($invoiceId, $path, disk:, overwrite:, options:)` streams an issued invoice's PDF from BeeL's pre-signed URL into any Laravel disk:
  - memory stays at `beel.downloads.buffer_bytes` whatever the PDF's size;
  - it writes to a temporary file, verifies the PDF signature, length and stored size, then moves it into place;
  - it retries with a fresh URL on transient failures;
  - it refuses an existing file unless `overwrite: true`.

  `Jobs\StoreInvoicePdf` does the same from the queue: it waits for BeeL's `Retry-After` while the PDF is generated, treats an existing file as done and fails at once on errors retrying can't fix. Its payload is encrypted, since it may hold an API key.

  `$company->invoices` is now `BeelCompanyInvoices`, which proxies the SDK resource. `BeelFake::invoicePdf()` and `BeelFake::pdf()` fake it.
- Per-type webhook events in `Events\Webhooks` (`InvoiceIssued`, `VerifactuStatusUpdated`, `InvoicePdfGenerated`, ...), dispatched right after `BeelWebhookReceived` for known types, with typed `data()`.
- `$company->invoices->storePreview()` (the invoice's WebP preview) and `$company->storeRepresentationDocument()` (the AEAT representation PDF) store those documents the same way as `storePdf()`. The download settings are `beel.downloads.*`, and the exceptions are `DocumentAlreadyExists` and `DocumentDownloadFailed`.
- The PSR-18 bridge hands the SDK Guzzle's response as is (body in `php://temp`) instead of rebuilding it from a copied string.
- `Jobs\Middleware\ThrottleBeelRequests` keeps queued jobs under BeeL's rate limit per API key (`beel.queue_rate_limit`, 250/min), releasing them until the window resets instead of provoking 429s. `StoreInvoicePdf` uses it and retries within a day, counting only exceptions.
- Config values are read typed: numbers may come as env strings, but a wrong type (e.g. an array for `beel.http.timeout`) fails with an error naming the key instead of silently becoming 0.
- `LaravelNetworkException` and `LaravelClientException` autoload like any other class.
- Document downloads count bytes by position, so storage adapters that read a body twice (the AWS SDK computes a checksum first) no longer reject a valid download as too long.
- The typed webhook events' `data()` checks the model it returns and throws `UnexpectedValueException` naming the event if the payload doesn't match.
- `beel:check --api-key= --company-id= --account-id=` diagnoses one tenant instead of the default credentials.
- Integrator support:
  - `BeelWebhookSubscriptions::allEvents()` and `beel:webhook:subscribe --provisioner-events` include the provisioner-only events.
  - `subscribe(accountRelationship:)` and `--account-relationship` receive events from the accounts you manage (`managed` or `all`; BeeL's default is `own`). `BeelWebhookSubscription` exposes `accountRelationship`.
  - `BeelWebhookReceived` exposes `accountRelationship` and `accountExternalRef`.
  - `beel:check` recognizes an integrator key (`accounts:*`) and warns when a subscription misses the provisioner events or only receives its own account's events.
- `BeelWebhookSubscriptions`, to create, rotate, find or delete webhook subscriptions programmatically (e.g. one per tenant): the secret is handed only to a `store` callback, a created subscription is deleted if storing fails, and a rotated secret that can't be stored travels in `RotatedWebhookSecretNotStored::$secret`.
- `php artisan beel:webhook:subscribe`, which creates this app's webhook subscription (or rotates its secret with `--rotate`) and writes the secret to `.env` in place (keeping owner, mode and symlinks), without printing it.
- `php artisan beel:check`, a read-only diagnosis of the key, account, company readiness, key scopes, webhook subscription, webhook secret and dedupe cache store.
- Test helpers for apps: the `Testing\InteractsWithBeelWebhooks` trait (`postBeelWebhook()`, with realistic `data` per event type by default) and `Testing\WebhookSignature::sign()`.
- `BeelFake::api()` (`Testing\BeelApiFake`) fakes BeeL operations by name (`->issueInvoice()`, `->listCustomers()`, `->invoicePdf()`, ...), with answers in order, so app tests don't depend on BeeL's routes.
- `Testing\BeelFake` for faking the API with `Http::fake()`: `ok()`, `page()`, `cursorPage()` and `error()` responses in BeeL's format, and realistic resources (`invoice()`, `customer()`, `identity()`, `issuingReadiness()`, `managedAccount()`, `webhookSubscription()`, `webhookDelivery()`, `webhookData()`) checked against the SDK.
- Laravel Boost guidelines and a `beel-invoicing` skill for AI coding agents, covering this package, the SDK, BeeL's API, VERI*FACTU rules, and what is out of scope (B2B e-invoicing, TicketBAI, SII).
