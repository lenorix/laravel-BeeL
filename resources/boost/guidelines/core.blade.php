{{-- Laravel BeeL guidelines for AI code assistants. Always loaded: keep it short, details go in the beel-invoicing skill. Keep free of Blade echo braces and directives: Boost silently drops a guideline that fails to render. --}}
## BeeL invoicing (lenorix/laravel-beel)

- `lenorix/laravel-beel` integrates BeeL (https://docs.beel.es), a Spanish invoicing API with VERI*FACTU built in, into Laravel on top of `lenorix/beel-sdk` (an unofficial SDK that ships no guidelines of its own).
- BeeL numbers and issues invoices, runs VERI*FACTU (hash chain, AEAT submission, QR), renders PDFs and sends emails. The app decides the fiscal content: invoice type, recipient, lines, taxes, exemptions and corrections.
- Activate the `beel-invoicing` skill for any work on invoices (facturas), corrective invoices (rectificativas), voiding, customers, products, series, taxes (IVA, IGIC, IPSI, IRPF, recargo de equivalencia), VERI*FACTU or AEAT status, NIFs, BeeL accounts or companies, invoice PDFs, BeeL webhooks or tests that fake BeeL. The rules below apply even without it.

### Getting a client

```php
use Lenorix\LaravelBeel\BeelManager;

$company = app(BeelManager::class)->company();   // services.beel.company_id; ->account() and ->client() too
$company = app(BeelManager::class)->company(apiKey: $tenant->beel_api_key, companyId: $tenant->beel_company_id);

$company->invoices->get($id);                     // SDK resources: invoices, customers, products, series, ...
foreach ($company->customers->all() as $customer) {} // every page, lazily
```

### Rules

- Get clients only through `BeelManager` (or the `LaravelBeel` facade), never `new Lenorix\BeelSdk\Beel(...)`: that skips Laravel's HTTP client, retries and `Http::fake()`. To take default credentials from somewhere other than config, bind `Lenorix\LaravelBeel\Contracts\CredentialsResolver`.
- `company_id` and `account_id` are BeeL UUIDs, never a NIF.
- Use company- and account-scoped resources; never the deprecated top-level `$beel->invoices`, `->customers`, `->products`, `->series`, `->configuration` or `->downloadPdf()`.
- Iterate lists with `all()`, not hand-written `page` loops.
- Never implement VERI*FACTU yourself (hash chaining, registro XML, signing, AEAT calls): BeeL does it.
- BeeL never recalculates amounts. Set every line's tax explicitly: `main_tax` (type, percentage, regime_key), an `exemption_reason` on 0 % lines, and `irpf_rate` `0` when nothing is withheld (omitted, it inherits the company default).
- Classify every line by where the customer is and what is sold (the skill's table has the full rules):

| Customer | Goods | Services |
|---|---|---|
| Spain | omit `exemption_reason`, rate > 0, regime `01` | same (ISP `ISP_ART_84_2_*` only for domestic reverse charge) |
| EU business, valid VIES VAT-ID (`alternative_id.type` `NIF_IVA`) | `EXENTA_ART_25`, 0 %, `01` | `NO_SUJETA_LOCALIZACION`, 0 %, `01` |
| EU consumer, seller under 10,000 EUR/year OSS | Spanish VAT, no reason, `01` | same |
| EU consumer, seller in OSS | destination rate, regime `17`, never an `exemption_reason` | same |
| Outside the EU | `EXENTA_ART_21`, 0 %, regime `02` | `NO_SUJETA_LOCALIZACION`, 0 %, `01` |

- Foreign customers never use `recipient.nif`: identify them with `alternative_id` (`NIF_IVA` for EU businesses, else `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT`), always on a `STANDARD` invoice. IGIC (Canarias) and IPSI (Ceuta, Melilla) follow the place of supply, not the issuer's address. Recargo de equivalencia only when the retailer declared it in writing (regime `18`). BeeL doesn't track the OSS threshold nor detect the place of supply: the app decides, and unclear cases go to a human.
- `issue()` is irreversible and the AEAT submission is asynchronous: read `verifactu.submission_status` or handle `verifactu.status.updated`; only `REJECTED` needs action. Correct issued invoices with `createCorrective()` (R1 to R5); `void()` only an invoice that should never have existed.
- Send an `Idempotency-Key` derived from your own domain id on create, issue, void and corrective calls: `$company->invoices->withOptions(new Lenorix\BeelSdk\Http\RequestOptions(idempotencyKey: 'invoice-issue-'.$id))->issue($id)`. Set `external_ref` when an invoice must exist once per order or payment.
- Never store the 5-minute URL from `getPdf()`. Store the PDF with `$company->invoices->storePdf($id, $path, disk: 's3')`, or from the queue with `Lenorix\LaravelBeel\Jobs\StoreInvoicePdf::dispatch($id, $path, disk: 's3')`. Never download it with `file_get_contents()` or `Http::get()->body()`.
- Queued jobs that call BeeL in bulk: add the `Lenorix\LaravelBeel\Jobs\Middleware\ThrottleBeelRequests` middleware (300 requests per minute per key) and use `retryUntil()` with `$tries = 0`, since each throttled release counts as an attempt.
- `beel_sk_test_` keys are sandbox, `beel_sk_live_` production (same base URL).
- Branch on `$e->apiCode` of `Lenorix\BeelSdk\Exception\BeelApiError`, never on the message. `BeelNotReadyError` (HTTP 202, `retryAfter`) is not a `BeelApiError`. Network failures are `Lenorix\LaravelBeel\LaravelNetworkException` / `LaravelClientException`.
- An app-rendered invoice document for a VERI*FACTU invoice (foreign customers included) must print the AEAT QR from `verifactu.qr_url`, following the skill's rules.

### Webhooks

- The package verifies `POST /beel/webhook` and dispatches `Lenorix\LaravelBeel\Events\BeelWebhookReceived` before answering 202. Set it up with `php artisan beel:webhook:subscribe`.
- Listeners run inside the request (BeeL gives up after 10 s): only filter and dispatch a queued job. Skip `$event->isTest()`. Deduplicate durably on `$event->id` (unique index). Prefer the typed per-type events (`Lenorix\LaravelBeel\Events\Webhooks\InvoiceIssued`, `VerifactuStatusUpdated`, `InvoicePdfGenerated`, ...; `$event->data()` typed, `$event->webhook` the generic event) over matching on `BeelWebhookReceived::$type`.
- Deduplication needs a shared atomic cache store (redis, memcached, database, dynamodb); with `array` or `null` it silently does nothing, so set `beel.webhook_dedupe_store`.
- Never put rate limiting, auth or CSRF middleware on the webhook route: BeeL doesn't retry 4xx.
- Multi-tenant: one subscription per tenant with `Lenorix\LaravelBeel\BeelWebhookSubscriptions`, a `WebhookSecretResolver` that picks the secret from the URL segment (never from the payload), and listeners that identify the tenant by `$event->webhookKey`.

### Diagnosing and testing

- `php artisan beel:check` diagnoses the setup (read-only); `--api-key`, `--company-id` and `--account-id` check one tenant.
- Tests must never reach BeeL: `Http::preventStrayRequests()` plus `Http::fake()` faked by operation name: `Lenorix\LaravelBeel\Testing\BeelFake::api()->issueInvoice(BeelFake::invoice())->listCustomers([BeelFake::customer()])->fake()` (answers in order, `BeelFake::error(422, 'VALIDATION_ERROR')` for errors). Post signed webhooks with the `Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks` trait (`$this->postBeelWebhook('invoice.issued')`).

### Out of scope

- Not covered by BeeL or this package: B2B e-invoicing (Crea y Crece, Facturae, UBL, Peppol, FACe), TicketBAI (País Vasco, Navarra), SII, and other countries' tax systems. Do not assume BeeL fulfils them.
