# Changelog

All notable changes to `laravel-beel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release. Requires PHP 8.4+, Laravel 11.23+, 12 or 13, and `lenorix/beel-sdk` 0.2+.

### Added

- `BeelManager` (and the `LaravelBeel` facade) to build `lenorix/beel-sdk` clients: `client()`, `company()` returning `BeelCompany`, and `account()` returning `BeelAccount`. Both scopes expose the SDK's resources plus `scope` and `raw`.
- Credentials from `config/services.php` (`services.beel.key`, `company_id`, `account_id`) by default. Explicit arguments always win. Bind `Contracts\CredentialsResolver` to take the defaults from anywhere else, such as a settings table or the current tenant; it is resolved on every call.
- All SDK traffic goes through Laravel's HTTP client, so HTTP events, `Http::fake()` and Guzzle options apply. Configurable timeouts and retries on connection errors, 429 and 5xx, honouring a 429's `Retry-After` (capped at 60 s). The SDK keeps adding `Idempotency-Key` to POST requests.
- Webhook endpoint `POST /beel/webhook/{beelWebhookKey?}` that verifies the `BeeL-Signature` HMAC against the raw body and answers 202:
  - It dispatches `Events\BeelWebhookReceived` after the response via `defer()`, with `id`, `type`, `data`, `payload`, `companyId`, `accountId`, `webhookKey` and `isTest()`.
  - The secret comes from `services.beel.webhook_secret` by default. Bind `Contracts\WebhookSecretResolver` for one secret per tenant, chosen from the optional URL segment.
  - Answers:
    - 401: implausible signature headers, rejected from the header alone before resolving the secret or reading the body.
    - 503 (retryable): a well-formed signature that doesn't match, e.g. just after a secret rotation, or no secret configured.
    - 400: a verified payload without `id`, `type` and `data`.
  - The replay tolerance is configurable (`webhook_replay_tolerance_seconds`, 300 s by default). The route skips `TrimStrings` and `ConvertEmptyStringsToNull` and is not rate limited, because BeeL never retries a 4xx.
- `php artisan beel:retry-webhook-deliveries`, a safety net for webhooks that never arrived:
  - It asks BeeL to redeliver events with no successful attempt that fall within `max_age_minutes`. Events whose latest attempt is under 2 minutes old are left for BeeL's automatic retries, and events stop being retried at `max_attempts`.
  - It logs and dispatches `Events\BeelWebhookDeliveryAbandoned` when it gives up on an event, and `Events\BeelWebhookSubscriptionInactive` for subscriptions BeeL has deactivated. It exits with failure so monitoring notices.
  - Retries carry a per-attempt `Idempotency-Key`, so overlapping runs never redeliver twice (a retry still in flight in another run is not a failure).
  - Checks one account by default. Bind `Contracts\WebhookRetryAccounts` to check several, each with its own key.
  - Optional automatic scheduling (`webhook_delivery_retry.schedule`, plus `on_one_server`).
  - The API key needs the `webhooks:read` and `webhooks:write` scopes.
- Laravel Boost guidelines and a `beel-invoicing` skill for AI coding agents, covering this package, the SDK, BeeL's API, VERI*FACTU rules, and what is out of scope (B2B e-invoicing, TicketBAI, SII).
