# Laravel BeeL

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/laravel-beel.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-beel)
[![Tests](https://github.com/lenorix/laravel-BeeL/actions/workflows/run-tests.yml/badge.svg)](https://github.com/lenorix/laravel-BeeL/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/laravel-beel.svg?style=flat-square)](https://packagist.org/packages/lenorix/laravel-beel)
[![Plumb score](https://plumbphp.dev/badges/lenorix/laravel-beel/composite.svg)](https://plumbphp.dev/lenorix/laravel-beel)

Laravel integration for [BeeL](https://docs.beel.es), the Spanish invoicing API with VERI\*FACTU built in.

It wraps the [`lenorix/beel-sdk`](https://github.com/lenorix/BeeL-php-sdk) client with Laravel config, Laravel's HTTP client and a ready-made webhook endpoint.

## Installation

```bash
composer require lenorix/laravel-beel
```

Add your BeeL settings to `config/services.php`:

```php
'beel' => [
    'key' => env('BEEL_API_KEY'),               // beel_sk_test_... (sandbox) or beel_sk_live_...
    'company_id' => env('BEEL_COMPANY_ID'),     // company UUID, not the NIF
    'account_id' => env('BEEL_ACCOUNT_ID'),     // optional
    'webhook_secret' => env('BEEL_WEBHOOK_SECRET'),
],
```

Then check everything is in place:

```bash
php artisan beel:check
```

It is read-only. It reports problems with the key, the company, webhooks and the cache.

## Usage

Get a client from `BeelManager` (or the `LaravelBeel` facade):

```php
use Lenorix\LaravelBeel\BeelManager;

$company = app(BeelManager::class)->company();

$invoice = $company->invoices->create($request);
$company->invoices->issue($invoice->getId());

foreach ($company->customers->all() as $customer) {
    // every page, fetched lazily
}
```

- `company()` gives you the company's resources: invoices, customers, products, series and more.
- `account()` gives you the account's: members, webhooks and emails.
- `client()` gives you the whole SDK.

Credentials come from config. You can pass them per call, which is handy for multi-tenant apps:

```php
$company = app(BeelManager::class)->company(apiKey: $tenant->beel_api_key, companyId: $tenant->beel_company_id);
```

To take the defaults from somewhere else, bind your own `Contracts\CredentialsResolver`.

## Webhooks

The package registers `POST /beel/webhook` and verifies BeeL's signature for you. To create the subscription in BeeL and save its secret to `.env`:

```bash
php artisan beel:webhook:subscribe
```

Then listen to the event:

```php
use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

Event::listen(function (BeelWebhookReceived $event): void {
    if ($event->isTest()) {
        return;
    }

    if ($event->type === 'verifactu.status.updated') {
        SyncVerifactuStatus::dispatch($event->id, $event->typed()->getData()->getInvoiceId());
    }
});
```

Three rules:

- **Keep listeners light.** They run before BeeL gets its answer, and BeeL gives up after 10 seconds. Dispatch a queued job for the real work. If a listener throws, BeeL retries the delivery.
- **Deduplicate on `$event->id` in your own data**, for example with a unique index. BeeL can redeliver an event. The package already drops repeats within 15 minutes.
- **Use a shared cache store** (redis, memcached, database or dynamodb) so that deduplication works. With `array` or `null` it silently does nothing; set `beel.webhook_dedupe_store` if your default store is one of them.

`$event->typed()` returns the SDK's typed model of the event. `$event->data` is the raw array.

## Testing

Tests never reach BeeL: all traffic goes through Laravel's HTTP client. `BeelFake` builds responses shaped like BeeL's:

```php
use Lenorix\LaravelBeel\Testing\BeelFake;

Http::preventStrayRequests();
Http::fake([
    '*/invoices/*' => BeelFake::ok(BeelFake::invoice(['status' => 'DRAFT'])),
    '*/customers*' => BeelFake::page('customers', [BeelFake::customer()]),
    '*/issue' => BeelFake::error(422, 'EMISSION_NOT_READY'),
]);
```

To test your webhook listeners, use the `InteractsWithBeelWebhooks` trait. It posts a correctly signed delivery:

```php
$this->postBeelWebhook('invoice.issued')->assertStatus(202);
```

The package retries faked 429 and 5xx responses; use `Sleep::fake()` to skip the waits.

## More features

Each of these is documented in its PHPDoc and in `config/beel.php`:

- **Storing invoice PDFs.** `$company->invoices->storePdf($id, 'invoices/A-42.pdf', disk: 's3')` streams the PDF into any Laravel disk. It uses bounded memory (a 64 KiB buffer, never the whole file) and writes atomically, and it verifies the file before moving it into place. It refuses to replace an existing file unless you pass `overwrite: true`.
- **Multi-tenant webhooks.** `BeelWebhookSubscriptions` creates, rotates and deletes one subscription per tenant. It hands each secret to your storage. `Contracts\WebhookSecretResolver` then picks the secret from the URL, `/beel/webhook/{key}`.
- **Recovering lost deliveries.** `php artisan beel:retry-webhook-deliveries` asks BeeL to redeliver the events that never arrived. You can schedule it with `beel.webhook_delivery_retry.schedule`. It needs the `webhooks:read` and `webhooks:write` scopes.
- **Integrators** (keys with `accounts:*` scopes):
  - `beel:webhook:subscribe --provisioner-events --account-relationship=all`;
  - `$event->accountRelationship` and `$event->accountExternalRef`.
- **Logs and metrics.** Reported BeeL errors carry `request_id`, `api_code` and `status_code` in their log context, also when your own exception wraps them. For metrics, listen to Laravel's HTTP client events.
- **HTTP settings.** Timeouts and retries are in `config/beel.php` (`php artisan vendor:publish --tag="beel-config"`).
- **Your own endpoint.** Set `beel.register_webhook_route` to `false` and use the SDK's `WebhookVerifier`.

## AI guidelines (Laravel Boost)

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines and a `beel-invoicing` skill for AI coding agents. They cover this package, the SDK, the BeeL API and the VERI\*FACTU rules. Run `php artisan boost:install` to pick them up.

## Requirements

- PHP 8.4+
- Laravel 13
- `lenorix/beel-sdk` 0.4.1+

## License

Released into the public domain under [The Unlicense](https://unlicense.org/). See [LICENSE.md](LICENSE.md).
