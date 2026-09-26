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
```

### Rules that must never be broken

- Get clients only through `BeelManager` or the `LaravelBeel` facade. Never `new Lenorix\BeelSdk\Beel(...)`: that bypasses Laravel's HTTP client, the configured timeouts and retries, and `Http::fake()`.
- `company_id` and `account_id` are BeeL UUIDs, never a NIF. Sending a NIF where a company id is expected fails.
- Use company- and account-scoped resources (`$company->invoices`, `$account->members`, ...). Never use the deprecated top-level `$beel->invoices`, `->customers`, `->products`, `->series`, `->configuration` or `->downloadPdf()`: they hit legacy routes BeeL is retiring.
- Never implement VERI*FACTU yourself: no hash chaining, no registro XML, no XAdES signing, no certificates, no AEAT web service calls. BeeL does all of it.
- BeeL never recalculates your amounts; what you send is the fiscal truth. Set every line's tax explicitly: `main_tax` (type, percentage, regime_key) is mandatory on normal lines, a 0 % line needs an `exemption_reason`, and an omitted `irpf_rate` inherits the company default, so send `0` explicitly when nothing must be withheld.
- `issue()` is irreversible: it assigns the definitive number and freezes the invoice. The AEAT submission is asynchronous, so a successful `issue()` does not mean the AEAT accepted it. Read `verifactu.submission_status` (or handle `verifactu.status.updated`); only `REJECTED` needs action.
- Issued invoices are immutable. Correct them with a corrective invoice (`createCorrective`, codes R1 to R5). Use `void()` only for an invoice that should never have existed; its number is burned forever.
- Send an `Idempotency-Key` header derived from your own domain id on create, issue, void and corrective calls, and set `external_ref` when an invoice must exist only once per order or payment. The package's automatic retries reuse the same key.
- An invoice PDF from `getPdf()` is a presigned URL that expires after about 5 minutes. Never store the URL; fetch it when needed. Drafts have no fiscal PDF (use `preview()`).
- The API key prefix picks the environment: `beel_sk_test_` is sandbox, `beel_sk_live_` is production, with the same base URL. A 404 can mean the key and the resource belong to different environments.
- API errors are `Lenorix\BeelSdk\Exception\BeelApiError` subclasses (`BeelValidationError`, `BeelConflictError`, `BeelNotFoundError`, `BeelAuthError`, `BeelRateLimitError`). Branch on `$e->apiCode`, never on the localized message, and log `$e->requestId`. Network failures are `Lenorix\LaravelBeel\LaravelNetworkException` or `LaravelClientException`, not `BeelApiError`.
- Invoices to foreign customers are still VERI*FACTU invoices (registered with the AEAT, with QR). If the app renders its own invoice document instead of BeeL's PDF, it must print the AEAT QR (from `verifactu.qr_url`) following the rules in the skill.

### Webhooks

- The package registers `POST /beel/webhook` (config `beel.webhook_path`), verifies the `BeeL-Signature` HMAC with `services.beel.webhook_secret`, answers 202 and then dispatches `Lenorix\LaravelBeel\Events\BeelWebhookReceived` (`$event->id`, `->type`, `->data`, `->payload`) after the response.
- Do the real work in a queued listener (`ShouldQueue` with `$tries` and `backoff()`). BeeL may redeliver an event, so deduplicate on `$event->id`. Ignore deliveries where `$event->isTest()` is true. Route multi-company apps on `$event->companyId`.
- BeeL does not retry deliveries answered with a 4xx. Never put rate limiting, auth or CSRF middleware in front of the webhook route. A wrong secret (401) loses events: recover them promptly with `$account->webhooks->listDeliveries()` and `->retryDelivery()`, since BeeL keeps only the last 50 delivery logs.
- With a custom `WebhookSecretResolver`, choose the secret from trusted request metadata (route, host), never from the unverified payload.

### Testing

- Tests must never reach the real BeeL API. All SDK traffic goes through Laravel's HTTP client, so use `Http::fake()` and `Http::preventStrayRequests()`.
- To test webhooks, sign the exact raw JSON you post: header `t=<unix time>,v1=` followed by `hash_hmac('sha256', "<t>.<raw json>", $secret)`.

### Not covered by BeeL or this package

- Mandatory B2B electronic invoicing (Ley 18/2022 Crea y Crece, Real Decreto 238/2026), structured formats (UBL, Facturae, CII), Peppol, FACe (public sector) and invoice status reporting. These are separate legal obligations; do not assume BeeL fulfils them.
- TicketBAI and the foral territories (País Vasco, Navarra), and SII taxpayers: they are outside VERI*FACTU.
- Tax systems of other countries.
