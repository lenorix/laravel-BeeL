{{-- Laravel BeeL guidelines for AI code assistants. Keep free of Blade echo braces and directives: Boost silently drops a guideline that fails to render. --}}
## BeeL invoicing (lenorix/laravel-beel)

- `lenorix/laravel-beel` integrates BeeL (https://docs.beel.es), a Spanish invoicing API with VERI*FACTU built in, into Laravel. It wraps `lenorix/beel-sdk`, an unofficial PHP SDK that is a transitive dependency and ships no guidelines of its own.
- BeeL numbers and issues invoices, builds the VERI*FACTU hash chain, submits the registros de facturación to the AEAT, returns the QR data, renders PDFs and sends emails. The app decides the fiscal content: invoice type, recipient, lines, taxes, exemptions and corrections.
- Always activate the `beel-invoicing` skill when working on invoices (facturas), drafts, issuing, corrective invoices (rectificativas), voiding, series, recurring invoices, customers, products, taxes (IVA, IGIC, IPSI, IRPF, recargo de equivalencia), foreign customers, VERI*FACTU or AEAT status, NIF validation, BeeL accounts or companies, invoice PDFs or emails, or BeeL webhooks. The rules below apply even without the skill.

### Getting a client

```php
use Lenorix\LaravelBeel\BeelManager;

$beel = app(BeelManager::class)->client();       // Lenorix\BeelSdk\Beel, over Laravel's HTTP client
$company = app(BeelManager::class)->company();   // company scope from services.beel.company_id
$account = app(BeelManager::class)->account();   // account scope from services.beel.account_id (optional)

// Credentials resolved at runtime (per tenant or any other reason) instead of config:
$company = app(BeelManager::class)->company(apiKey: $tenant->beel_api_key, companyId: $tenant->beel_company_id);

$company->invoices->list(['page' => 1]);          // scoped SDK resources: invoices, customers, products, series, ...
foreach ($company->invoices->all(['limit' => 100]) as $invoice) {} // lazy iteration over every page
```

### Rules that must never be broken

- Iterate every page with the resources' `all()` generators instead of hand-written `page` loops. Read the API key's account, environment and scopes with `$beel->me->identity()`; drop to `->raw` only for operations without a resource method.

- Default credentials come from config (`services.beel.*`) through the bound `Lenorix\LaravelBeel\Contracts\CredentialsResolver`. When the app keeps keys elsewhere (database, current tenant), bind its own `CredentialsResolver` instead of passing credentials around or copying them into config at runtime.
- Get clients only through `BeelManager` or the `LaravelBeel` facade. Never `new Lenorix\BeelSdk\Beel(...)`: that bypasses Laravel's HTTP client, the configured timeouts and retries, and `Http::fake()`.
- `company_id` and `account_id` are BeeL UUIDs, never a NIF. Sending a NIF where a company id is expected fails.
- Use company- and account-scoped resources (`$company->invoices`, `$account->members`, ...). Never use the deprecated top-level `$beel->invoices`, `->customers`, `->products`, `->series`, `->configuration` or `->downloadPdf()`: they hit legacy routes BeeL is retiring.
- Never implement VERI*FACTU yourself: no hash chaining, no registro XML, no XAdES signing, no certificates, no AEAT web service calls. BeeL does all of it.
- BeeL never recalculates your amounts; what you send is the fiscal truth. Set every line's tax explicitly: `main_tax` (type, percentage, regime_key) is mandatory on normal lines, a 0 % line needs an `exemption_reason`, and an omitted `irpf_rate` inherits the company default, so send `0` explicitly when nothing must be withheld.
- `issue()` is irreversible: it assigns the definitive number and freezes the invoice. The AEAT submission is asynchronous, so a successful `issue()` does not mean the AEAT accepted it. Read `verifactu.submission_status` (or handle `verifactu.status.updated`); only `REJECTED` needs action.
- Issued invoices are immutable. Correct them with a corrective invoice (`createCorrective`, codes R1 to R5). Use `void()` only for an invoice that should never have existed; its number is burned forever.
- Send an `Idempotency-Key` derived from your own domain id on create, issue, void and corrective calls (`$company->invoices->withOptions(new Lenorix\BeelSdk\Http\RequestOptions(idempotencyKey: ...))->issue($id)`, or the method's `$headers` argument), and set `external_ref` when an invoice must exist only once per order or payment. The package's automatic retries reuse the same key.
- An invoice PDF from `getPdf()` is a presigned URL that expires after about 5 minutes. Never store the URL; fetch it when needed. Drafts have no fiscal PDF (use `preview()`).
- The API key prefix picks the environment: `beel_sk_test_` is sandbox, `beel_sk_live_` is production, with the same base URL. A 404 can mean the key and the resource belong to different environments.
- API errors are `Lenorix\BeelSdk\Exception\BeelApiError` subclasses (`BeelValidationError`, `BeelConflictError`, `BeelNotFoundError`, `BeelAuthError`, `BeelRateLimitError`). Branch on `$e->apiCode`, never on the localized message. Reported BeeL errors already get `beel_request_id`, `beel_api_code` and `beel_status` in their log context; for metrics listen to Laravel's HTTP client events (`ResponseReceived`, `ConnectionFailed`) filtered on BeeL's host. Network failures are `Lenorix\LaravelBeel\LaravelNetworkException` or `LaravelClientException`, not `BeelApiError`.
- Invoices to foreign customers are still VERI*FACTU invoices (registered with the AEAT, with QR). If the app renders its own invoice document instead of BeeL's PDF, it must print the AEAT QR (from `verifactu.qr_url`) following the rules in the skill.

### Webhooks

- The package registers `POST /beel/webhook/{beelWebhookKey?}` (config `beel.webhook_path`), verifies the `BeeL-Signature` HMAC with `services.beel.webhook_secret`, dispatches `Lenorix\LaravelBeel\Events\BeelWebhookReceived` (`$event->id`, `->type`, `->data`, `->payload`, `->companyId`, `->accountId`, `->webhookKey`) before answering 202; if a listener throws it answers 503 so BeeL retries.
- Prefer `$event->typed()->getData()` (the SDK's per-type model, e.g. `WebhookEventDataInvoiceIssued`) over reading `$event->data` array keys; it stays an array for event types the SDK doesn't know.
- Keep `BeelWebhookReceived` listeners light: they run inside the webhook request (BeeL gives up after 10 seconds). Filter and route in the listener, then dispatch a queued job (`ShouldQueue` with `$tries` and `backoff()`) for the real work. BeeL may redeliver an event; the package already drops redeliveries and simultaneous duplicates within `beel.webhook_dedupe_seconds` (15 min) through an atomic `Cache::add()` claim (keyed on the signed event id and the verifying secret, lasting at least twice the replay tolerance), but listeners must still deduplicate on `$event->id` durably (unique index). That claim only works on a cache store with atomic adds shared by every process receiving webhooks: redis, memcached, database or dynamodb (file only for a single server). Never rely on the `array` or `null` stores for it (not atomic, not shared: deduplication silently does nothing); set `beel.webhook_dedupe_store` when the default store is one of them. Ignore deliveries where `$event->isTest()` is true. In single-tenant apps route on `$event->companyId`; in multi-tenant apps identify the tenant by `$event->webhookKey` (the URL segment) and ignore events whose `companyId`/`accountId` don't belong to it. That is only trustworthy when the resolver returns a distinct secret per key and null for unknown or missing keys; with the default resolver any segment is accepted with the config secret.
- BeeL does not retry deliveries answered with a 4xx. Never put rate limiting, auth or CSRF middleware in front of the webhook route. A signature that doesn't match the configured secret gets a retryable 503 (covers a just-rotated secret); anything else invalid gets a non-retryable 401. To recover events that never arrived, run or schedule `php artisan beel:retry-webhook-deliveries` (or set `beel.webhook_delivery_retry.schedule`): it asks BeeL to redeliver events with no successful attempt, and dispatches `BeelWebhookDeliveryAbandoned` / `BeelWebhookSubscriptionInactive` (plus a log warning and a failure exit code) when it gives up on an event or finds a deactivated subscription; listen to those to alert or resync. Do not dispatch `BeelWebhookReceived` from delivery logs yourself; redelivery keeps BeeL's history accurate and exercises the real endpoint.
- Multi-tenant webhooks: create, rotate, find or delete each tenant's subscription with `Lenorix\LaravelBeel\BeelWebhookSubscriptions` (`subscribe(store: fn (string $secret) => ..., webhookKey: ..., apiKey: ..., accountId: ...)`). The secret only reaches the `store` callback; persist it (encrypted) where the tenant's `WebhookSecretResolver` reads it. Never create two subscriptions for one URL.
- Integrators (keys with the privileged `accounts:*` scopes that manage provisioned accounts) also need the provisioner-only events `account.claimed`, `company.created` and `representation.signed`: pass `--provisioner-events` to `beel:webhook:subscribe`, or `events: $subscriptions->allEvents()` to `BeelWebhookSubscriptions::subscribe()`. Other keys can't subscribe to them.
- To set up the webhook, run `php artisan beel:webhook:subscribe`: it creates the subscription for `APP_URL` + the webhook path and writes the secret to `.env` (never printed); `--rotate` replaces an existing subscription's secret. Do not create a second subscription for the same URL.
- The secret defaults to `services.beel.webhook_secret`. For one secret per tenant, point each BeeL subscription at `route('beel.webhook', ['beelWebhookKey' => ...])` and bind a `WebhookSecretResolver` that reads `$request->route('beelWebhookKey')`; never choose the secret from the unverified payload.

### Diagnosing

- Run `php artisan beel:check` to diagnose the setup (key and environment, account, company readiness and blockers, key scopes, webhook subscription URL and status, webhook secret, dedupe cache store). It is read-only; errors exit 1.

### Testing

- Tests must never reach the real BeeL API. All SDK traffic goes through Laravel's HTTP client, so use `Http::fake()` and `Http::preventStrayRequests()`.
- Build fake responses with `Lenorix\LaravelBeel\Testing\BeelFake` instead of hand-written JSON: `BeelFake::ok(BeelFake::invoice([...]))`, `BeelFake::page('customers', [BeelFake::customer()])`, `BeelFake::error(422, 'VALIDATION_ERROR')`. Faked 429/5xx are retried by the package: use `Sleep::fake()` or set `beel.http.retries` to 0.
- To test webhook listeners, use the `Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks` trait: `$this->postBeelWebhook('invoice.issued', $data, $overrides)` posts a correctly signed delivery with a fresh event id. For hand-built requests, `Lenorix\LaravelBeel\Testing\WebhookSignature::sign($rawBody, $secret)` signs the exact body you send.

### Not covered by BeeL or this package

- Mandatory B2B electronic invoicing (Ley 18/2022 Crea y Crece, Real Decreto 238/2026), structured formats (UBL, Facturae, CII), Peppol, FACe (public sector) and invoice status reporting. These are separate legal obligations; do not assume BeeL fulfils them.
- TicketBAI and the foral territories (País Vasco, Navarra), and SII taxpayers: they are outside VERI*FACTU.
- Tax systems of other countries.
