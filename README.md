# Laravel BeeL

Laravel integration for the [`lenorix/beel-sdk`](https://github.com/lenorix/BeeL-php-sdk) client. The SDK handles the BeeL API itself; this package wires it into Laravel with config, the Laravel HTTP client, tenant-scoped clients, and webhook events.

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

Company scopes expose the SDK's resource objects directly, plus `scope` (the original SDK scope) and `raw` (the generated client for endpoints not covered by resource wrappers). `company->invoices->getPdf($id)` returns the SDK's PDF response, including its temporary download URL.

Passing per-tenant credentials is also supported when an application needs it, but is optional:

```php
$company = app(BeelManager::class)->company(
    apiKey: $tenant->beel_api_key,
    companyId: $tenant->beel_company_id,
);
```

## Laravel HTTP client

Requests pass through Laravel's HTTP client, so Laravel HTTP events and configured Guzzle options are available. Configure `timeout`, `connect_timeout`, `retries`, `retry_delay_ms`, and optional Guzzle `options` in `config/beel.php`. Retries apply to connection errors, HTTP 429, and 5xx responses.

## Webhooks

By default, the package registers a POST route at `/beel/webhook`, verifies the exact raw request body against the `BeeL-Signature` HMAC header, and responds with 202. Invalid signatures return 401. Once the signature is verified, `BeelWebhookReceived` is dispatched via [`defer()`](https://laravel.com/docs/12.x/helpers#method-defer), so it runs after the 202 response has already been sent back to BeeL and never adds listener latency to the webhook round-trip. The event provides the event `type`, its `data`, and the complete `payload`; listeners that need to survive a worker restart or guarantee delivery under load should still implement `ShouldQueue`, since `defer()` only protects response latency, not delivery.

```php
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

Event::listen(BeelWebhookReceived::class, function (BeelWebhookReceived $event): void {
    // $event->type, $event->data, $event->payload
});
```

Disable the automatic route with `register_webhook_route => false` to register an application-owned endpoint. You can still use the SDK's `WebhookVerifier` directly. For tenant-specific secrets, replace the `WebhookSecretResolver` binding and resolve the secret from trusted request metadata (such as a route identifier or known endpoint) before verifying the body. Do not select a secret based on unverified payload contents.

## Requirements

- PHP 8.4+
- Laravel 11.23+, 12, or 13 (webhook processing uses `defer()`, added in Laravel 11.23)
- `lenorix/beel-sdk` 0.2+

## License

MIT. See [LICENSE.md](LICENSE.md).
