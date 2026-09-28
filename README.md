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

Credentials live in `config/services.php`, like any Laravel service. Everything else (webhook route, cache, HTTP and PDF settings) lives in `config/beel.php`, which you only publish to change a default: `php artisan vendor:publish --tag="beel-config"`.

Then check everything is in place:

```bash
php artisan beel:check
```

It is read-only. It reports problems with the key, the company, webhooks and the cache. To check one tenant, pass `--api-key`, `--company-id` and `--account-id` (the key shows in `ps` and shell history).

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

Each known type also has its own event, dispatched right after, so you can listen to just what you need. Its `data()` is typed, and `$event->webhook` is the `BeelWebhookReceived`:

```php
use Lenorix\LaravelBeel\Events\Webhooks\VerifactuStatusUpdated;

Event::listen(function (VerifactuStatusUpdated $event): void {
    SyncVerifactuStatus::dispatch($event->webhook->id, $event->data()->getInvoiceId());
});
```

For example, to archive every invoice PDF as soon as BeeL generates it:

```php
use Lenorix\LaravelBeel\Events\Webhooks\InvoicePdfGenerated;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePdf;

Event::listen(function (InvoicePdfGenerated $event): void {
    if (! $event->webhook->isTest()) {
        $invoiceId = $event->data()->getInvoiceId();

        StoreInvoicePdf::dispatch($invoiceId, "invoices/{$invoiceId}.pdf", disk: 's3', companyId: $event->webhook->companyId);
    }
});
```

The job downloads it in the background and waits if the PDF isn't ready yet. Dispatching it twice for the same event is harmless.

If you use [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary), chain the job with the step that adds the PDF to your model. `storePdf()` verifies the download, which `addMediaFromUrl()` wouldn't:

```php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePdf;

$path = "beel-tmp/{$invoice->beel_invoice_id}.pdf";

Bus::chain([
    new StoreInvoicePdf($invoice->beel_invoice_id, $path, disk: 's3', overwrite: true),
    function () use ($invoice, $path) {
        $invoice->addMediaFromDisk($path, 's3')->toMediaCollection('pdf');
        Storage::disk('s3')->delete($path);
    },
])->catch(function () use ($path) {
    Storage::disk('s3')->delete($path);
})->dispatch();
```

The second step runs only once the PDF is stored, even if the job had to wait for BeeL. The temporary file goes to a shared disk (`s3` here), so it works even when queue workers run on different servers.

## Testing

Tests never reach BeeL: all traffic goes through Laravel's HTTP client. `BeelFake` builds responses shaped like BeeL's:

```php
use Lenorix\LaravelBeel\Testing\BeelFake;

Http::preventStrayRequests();

BeelFake::api()
    ->createInvoice(BeelFake::invoice(['status' => 'DRAFT']))
    ->issueInvoice(BeelFake::error(422, 'EMISSION_NOT_READY'))  // answers in order,
    ->issueInvoice(BeelFake::invoice())                          // the last one repeats
    ->listCustomers([BeelFake::customer()])
    ->invoicePdf()                                               // the PDF link and its download
    ->fake();
```

It fakes operations by name, so your tests don't depend on BeeL's routes. For anything else, `->on('GET', '/v1/companies/{company}/series', ...)` or plain `Http::fake()` with `BeelFake::ok()`, `page()` and `error()`.

To test your webhook listeners, use the `InteractsWithBeelWebhooks` trait. It posts a correctly signed delivery:

```php
$this->postBeelWebhook('invoice.issued')->assertStatus(202);
```

Set `beel.http.retries` to `0` in tests: otherwise a faked 429 or 5xx is retried, with real waits.

## More features

Each of these is documented in its PHPDoc and in `config/beel.php`:

- **Storing invoice PDFs.** `$company->invoices->storePdf($id, 'invoices/A-42.pdf', disk: 's3')` streams the PDF into any Laravel disk. It uses bounded memory (a 64 KiB buffer, never the whole file) and writes atomically, and it verifies the file before moving it into place. It refuses to replace an existing file unless you pass `overwrite: true`. To do it in the background, dispatch `StoreInvoicePdf::dispatch($id, $path, disk: 's3')`. The other documents have their own jobs too: `StoreInvoicePreview`, `StoreInvoicePreviewPdf`, `StoreInvoicePdfArchive`, `StoreInvoiceExport` and `StoreRepresentationDocument`. Each dispatches `BeelDocumentStored` once the file is stored.
- **Storing BeeL documents.** Also `storePreview()` (preview image), `storePreviewPdf()` (a draft's PDF preview), `storePdfArchive()` (ZIP of up to 500 invoice PDFs) and `storeExport()` (spreadsheet), and `$company->storeRepresentationDocument()` (the AEAT representation PDF), with the same guarantees. Archives and exports return BeeL's counts and are never re-requested, since BeeL rebuilds them each time.
- **Multi-tenant webhooks.** `BeelWebhookSubscriptions` creates, rotates and deletes one subscription per tenant. It hands each secret to your storage. `Contracts\WebhookSecretResolver` then picks the secret from the URL, `/beel/webhook/{key}`.
- **Recovering lost deliveries.** `php artisan beel:retry-webhook-deliveries` asks BeeL to redeliver the events that never arrived. You can schedule it with `beel.webhook_delivery_retry.schedule`. It needs the `webhooks:read` and `webhooks:write` scopes.
- **Integrators** (keys with `accounts:*` scopes):
  - `beel:webhook:subscribe --provisioner-events --account-relationship=all`;
  - `$event->accountRelationship` and `$event->accountExternalRef`.
- **Logs and metrics.** Reported BeeL errors carry `request_id`, `api_code` and `status_code` in their log context, also when your own exception wraps them. For metrics, listen to Laravel's HTTP client events.
- **Bulk work in queues.** Add the `ThrottleBeelRequests` middleware to jobs that call BeeL. They then wait in the queue instead of hitting BeeL's limit of 300 requests per minute per key. `StoreInvoicePdf` already uses it.
- **HTTP settings.** Timeouts and retries are in `config/beel.php` (`php artisan vendor:publish --tag="beel-config"`).
- **Your own endpoint.** Set `beel.register_webhook_route` to `false` and use the SDK's `WebhookVerifier`.

## AI guidelines (Laravel Boost)

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines and a `beel-invoicing` skill for AI coding agents. They cover this package, the SDK, the BeeL API and the VERI\*FACTU rules. Run `php artisan boost:install` to pick them up.

## Requirements

- PHP 8.4+
- Laravel 13
- `lenorix/beel-sdk` 0.6.2+

## License

Released into the public domain under [The Unlicense](https://unlicense.org/). See [LICENSE.md](LICENSE.md).
