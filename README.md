# Laravel BeeL

An optional Laravel integration for the standalone [`lenorix/beel-sdk`](https://github.com/lenorix/BeeL-php-sdk). The SDK remains the source of truth for BeeL endpoints, authentication, request models, idempotency keys, and webhook signature verification. This package adds Laravel configuration, HTTP client integration, tenant-scoped clients, and webhook events.

## Installation

```bash
composer require lenorix/laravel-beel
```

Add the BeeL API settings to `config/services.php`:

```php
'beel' => [
    'key' => env('BEEL_API_KEY'),
    'company_id' => env('BEEL_COMPANY_ID'),
    'base_url' => env('BEEL_BASE_URL', 'https://app.beel.es/api'),
    'webhook_secret' => env('BEEL_WEBHOOK_SECRET'),
],
```

Publish the integration options if you need to change the webhook route or HTTP settings:

```bash
php artisan vendor:publish --tag="beel-config"
```

The config file is `config/beel.php`. It controls automatic route registration and Laravel HTTP timeouts/retries. Laravel owns retries in this integration; the SDK retry layer is disabled to avoid stacking retries, and the SDK continues to generate `Idempotency-Key` headers for POST requests.

## Use the default company

The facade and container resolve to `BeelManager`:

```php
use Lenorix\LaravelBeel\BeelManager;

$company = app(BeelManager::class)->company();
$invoice = $company->invoices->create($request);
```

Or use the facade:

```php
$company = LaravelBeel::company();
```

`company_id` is the BeeL company UUID. It is independent from the API key.

## Tenant credentials

Pass tenant credentials when creating a scope. Each call creates a new SDK client and transport, so authenticated clients are never shared between tenants:

```php
$company = app(BeelManager::class)->company(
    apiKey: $tenant->beel_api_key,
    companyId: $tenant->beel_company_id,
);

$invoice = $company->invoices->create($request);
```

For account resources or access to the full SDK client, use `client()`:

```php
$beel = app(BeelManager::class)->client(apiKey: $tenant->beel_api_key);
$account = $beel->account($tenant->beel_account_id);
$rawClient = $beel->raw;
```

The returned company scope exposes the SDK's resource objects directly, along with `scope` (the original SDK scope) and `raw` (the generated client for endpoints not yet covered by a resource wrapper). For example, `company->invoices->getPdf($id)` returns the SDK's PDF response, including its temporary download URL.

## Laravel HTTP client

Requests pass through Laravel's HTTP client, so Laravel HTTP events and configured Guzzle options are available. Configure `timeout`, `connect_timeout`, `retries`, `retry_delay_ms`, and optional Guzzle `options` in `config/beel.php`. Retries apply to connection errors, HTTP 429, and 5xx responses.

## Webhooks

By default, the package registers a POST route at `/beel/webhook`, verifies the exact raw request body against the `BeeL-Signature` HMAC header, dispatches `BeelWebhookReceived`, and responds with 202. Invalid signatures return 401. The event provides the event `type`, its `data`, and the complete `payload`; listeners can implement processing or queue work.

```php
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

Event::listen(BeelWebhookReceived::class, function (BeelWebhookReceived $event): void {
    // $event->type, $event->data, $event->payload
});
```

Disable the automatic route with `register_webhook_route => false` to register an application-owned endpoint. You can still use the SDK's `WebhookVerifier` directly. For tenant-specific secrets, replace the `WebhookSecretResolver` binding and resolve the secret from trusted request metadata (such as a route identifier or known endpoint) before verifying the body. Do not select a secret based on unverified payload contents.

## Requirements

- PHP 8.4+
- Laravel 11, 12, or 13
- `lenorix/beel-sdk` 0.2+

## License

MIT. See [LICENSE.md](LICENSE.md).
