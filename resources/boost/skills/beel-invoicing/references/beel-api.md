# BeeL API behaviour

Summarised from https://docs.beel.es (llms-full.txt and the OpenAPI spec at https://docs.beel.es/api/openapi) on 2026-09-26; the VERI*FACTU, tax, international, regime-key and Stripe sections were rechecked against the official guides on 2026-09-27. Limits, codes and dates change: when a detail matters, confirm it at the source. JSON field names below are snake_case; SDK models expose them as camelCase getters and setters.

## What BeeL covers

- Covers: numbering from series, freezing totals at issue, the VERI*FACTU hash chain and signed registro de facturación, asynchronous submission to the AEAT with retries on transient errors, the registro de anulación when voiding, QR data (`verifactu.qr_url`, `qr_base64`), PDF rendering and templates, email delivery, NIF census validation, recurring invoices, Stripe auto-invoicing.
- BeeL never recalculates totals: "your numbers are the fiscal truth".
- The integrator decides `type`, `rectification_code`/`rectification_type`, per-line `exemption_reason`, `regime_key`, tax type and rate, and recipient identification.
- Not supported: F3 invoices (use an R5 `TOTAL` corrective and a new F1), autofactura or invoices issued by third parties, standalone abonos (always a corrective), a public subsanación endpoint, one corrective covering several originals, regime keys `12`, `13`, `16`, recargo de equivalencia at 1.75 %.
- Not mentioned anywhere in BeeL's docs: TicketBAI, foral territories, SII, Crea y Crece B2B e-invoicing, Facturae, UBL, Peppol. Treat them as unsupported (see `spain-invoicing-scope.md`).

## Authentication and environments

- `Authorization: Bearer beel_sk_...`. `beel_sk_test_` keys are sandbox (no real AEAT; test NIFs go to the AEAT test environment), `beel_sk_live_` keys are production. Same base URL, `https://app.beel.es/api` with `/v1/...` paths.
- Keys are shown once, never expire, and their scopes are fixed at creation. `accounts:read`/`accounts:write` are granted only by BeeL. Grant only the scopes the app's features use: `webhooks:read` and `webhooks:write` are needed by `beel:retry-webhook-deliveries`; note `webhooks:write` can also rotate the webhook secret.
- A managed account's `access_level` caps what a key can do regardless of its scopes.
- `401 UNAUTHORIZED` / `INVALID_API_KEY`: the key. `403 FORBIDDEN` / `INSUFFICIENT_SCOPE`: see `details.missing_scopes`.
- A 404 is deliberately ambiguous: wrong environment or no access.

## Errors

- RFC 9457 problem fields (`type`, `title`, `detail`, `instance`, `errors[{field, code}]`) plus `error{code, message, details}` and `meta{timestamp, request_id}`. `meta.request_id` equals the `X-Request-Id` header; log it.
- Branch on the code (`BeelApiError::$apiCode`), never on the message: messages are localised via `Accept-Language` (es, en, ca).
- Common codes:

| Status / code | Action |
|---|---|
| 422 `VALIDATION_ERROR` (also semantic rejections) | Fix the request |
| 400 `BUSINESS_RULE_VIOLATION` | Not retryable |
| 422 `NIF_NOT_IN_CENSUS` | Do not retry in a loop; it consumes AEAT quota |
| 409 `DUPLICATE_RESOURCE` | Already exists |
| 409 `INVOICE_DUPLICATE_EXTERNAL_REFERENCE` | Fetch the existing invoice by `external_ref` |
| 409 `CONCURRENT_MODIFICATION` | Re-read and retry |
| 402/403/429 plan or quota errors | Follow `details.action` |

- Rate limits: fixed 60 s window, 300 requests/min per credential (standard), 1000/min per IP (global, shared by every key on the host; extra keys don't raise it). A 429 carries `Retry-After`, `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` (only on 429).
- Request bodies are capped at 2 MB. Unknown query parameters are rejected with 400.
- The deprecated flat routes (`/v1/invoices`, ... with a `BeeL-Active-Company` header) retire on 2026-12-09.

## Idempotency and external_ref

- Header `Idempotency-Key` exactly (other spellings are ignored), on POST only. Letters, digits, `-`, `_`; max 255 chars. Stored 24 h, per user and environment, bound to path and body. Only 2xx responses are stored, so a failed call frees the key.
- A replay returns header `Idempotency-Replay: true`, possibly with 200 instead of 201.
- `409 IDEMPOTENCY_KEY_PROCESSING`: wait and retry with the same key. `409 IDEMPOTENCY_KEY_MISMATCH`: the body changed; use a new key.
- Rule: same intent and body, same key; changed body, new key. Derive keys from your own ids, e.g. `invoice-issue-<invoice id>`.
- `external_ref`: at most one live STANDARD/SIMPLIFIED invoice per issuer with a given value; deleting the draft frees it; look it up with `?external_ref=`. Use it for "one invoice per order/payment". `external_reference` is a deprecated alias.

## Pagination

`page`/`limit` with `pagination{current_page, total_pages, total_items, items_per_page, has_next, has_previous}`. Some lists (request logs, provisioned accounts) use `next_cursor`/`prev_cursor`.

## Accounts and companies

- Account (`account_id`): the tenant that pays and authenticates; owns keys, members and webhook subscriptions. Get it from `GET /v1/me/identity` (`$beel->me->identity()`).
- Company (`company_id`): one NIF with its own invoices, customers, products and series, separate in test and production. Always a UUID; sending a NIF returns 400. The API key carries no "current company".
- Create: `POST /v1/accounts/{account_id}/companies`. The NIF must exist in the AEAT census even in sandbox. `entity_type` (`INDIVIDUAL`/`LEGAL_ENTITY`) is immutable. `aeat_environment` TEST or PROD; PROD without a card returns `402 CHECKOUT_REQUIRED`. `activate` (default true) seeds series F/S/R and tax defaults. `NIF_ALREADY_REGISTERED` returns the existing id in `details.company_id`.
- Activation per environment (`POST .../activations`, `DELETE .../activations?environment=PROD`, scheduled). Read `in_test`/`in_prod`; the `environment` field is deprecated. A company active in production cannot be deleted (`409 COMPANY_ACTIVE_IN_PRODUCTION`).
- Issuing readiness (`issuingReadiness()`): `ready` plus `blockers` such as `COMPANY_HAS_NO_NIF`, `SERIES_DEFAULT_NOT_FOUND`, `ENV_MISMATCH`, `NIF_NOT_REGISTERED`, `NIF_REPRESENTATION_REQUIRED`. Check it during onboarding instead of discovering blockers at issue time.
- AEAT representation: the document by which the taxpayer authorises BeeL to submit on its behalf. Generate, download (presigned URL), have the holder sign it digitally, submit, poll status. Production AEAT submission for a NIF stays blocked until it is signed; the `representation.signed` webhook goes only to the provisioner.
- Managed accounts (integrators/provisioners: platforms, agencies or fleets operating accounts on behalf of their customers; see "Integrators" below). Member roles `OWNER`/`ADMIN`/`MEMBER`.

### Integrators (managed accounts)

- Scopes: `accounts:read` (list your portfolio, usage) and `accounts:write` (provision, change access level, end management) are privileged: not self-assignable in Settings > API Keys, BeeL grants them to integrators on request (contact BeeL; no public process). Every result is scoped to accounts you provisioned. Operating inside them still needs the normal scopes (`companies:read`, `customers:write`, `invoices:write`, ...). `account:admin` is never granted to API keys.
- Provision: `POST /v1/accounts` (email, display name, language, `access_level`, optional `tax_profile` that also creates the company). Idempotent by `external_ref`: resending returns the same `account_id` and a fresh `claim_token` while unclaimed. Returns `account_id`, `claim_token`/`claim_url` (shown once) and `company_id` when a tax profile was sent.
- Claim: the holder redeems the claim token on BeeL's web app (no API for it) to set a password and own the account. Status `PROVISIONED` -> `CLAIMED` -> `ACTIVE`. Claiming keeps your access level and you keep paying.
- Access level: `OPERATE` (invoices, customers, payment connections; production needs the signed AEAT representation), `VIEW` (read-only), `NONE` (no access, still billed). After the claim only the holder can raise it; you can keep or lower it (`PATCH /v1/accounts/{id}/access-level`).
- Representation: `POST /v1/companies/{company_id}/representation`, download, holder signs digitally, submit, poll; production AEAT submission for that NIF is blocked until signed. Sandbox issues immediately. Check `GET /v1/companies/{id}/issuing-readiness`.
- End management: `DELETE /v1/accounts/{id}/management` stops billing from the next cycle. Re-provisioning the same email reactivates an unclaimed account; a claimed one returns `409 PROVISIONING_ACCOUNT_CLAIMED` (needs the holder's consent).
- Billing: the provisioner pays every account it provisioned, claimed or not, empty or not; `GET /v1/accounts/{id}/usage` counts `nifs` as billable units. End management of accounts you no longer use.
- Webhooks for managed accounts: a subscription's `account_relationship` is `own` (default, only your account), `managed` (accounts you provisioned, if the management relationship grants data visibility; billing-only doesn't) or `all`. Delivered events carry `account_relationship` (`own`/`managed`), `account_id` and `account_external_ref` to route on.
- Provisioner-only webhook events: `account.claimed`, `company.created`, `representation.signed` (`BeelWebhookSubscriptions::defaultEvents()` leaves them out; pass `events: allEvents()` or `--provisioner-events`).
- `default_irpf_rate` has no default. Never preselect an IRPF rate for a customer's company: absent (not declared) is not the same as `0` (declared exempt).

## Invoice lifecycle

- Types: `STANDARD` (AEAT F1), `SIMPLIFIED` (F2), `CORRECTIVE` (created only via the corrective endpoint), `PROFORMA` (non-fiscal, numbered `PRO-...`, never sent to the AEAT).
- Statuses: `SCHEDULED`, `DRAFT`, `ISSUED`, `SENT`, `PAID`, `OVERDUE`, `RECTIFIED`, `VOIDED`, `CONVERTED`, `ACTIVE`, `EXPIRED`.
- `issue_date` is always today (set by the server, anti-fraud law); record an earlier operation with `operation_date`, and use scheduling for a future issue date.
- Create body: `series_id` (default series if omitted), `operation_date`, `due_date` (not in the past), `recipient`, `lines` (at least one), `payment_info{method, iban, swift, payment_term_days}`, `notes`, `external_ref`, `metadata`, `options{issue_directly, wait_for_pdf, send_automatically, email_config, attach_source_invoices}`.
- `payment_info.method`: `NONE`, `BANK_TRANSFER` (default, needs `iban`), `CARD`, `CASH`, `CHECK`, `DIRECT_DEBIT`, `BIZUM`, `OTHER`.
- Issue is irreversible and assigns the number. With VERI*FACTU enabled the recipient's census status is checked before numbering: an uncensused recipient returns 422 and no number is consumed. AEAT submission and PDF generation happen asynchronously after the 2xx.
- Drafts: editable (`PATCH`), deletable; issued invoices are immutable.
- Commercial status (`setStatus`): `PAID` from ISSUED/SENT/OVERDUE, `SENT` from ISSUED, `ISSUED` only to undo SENT.
- Scheduling: `{scheduled_for, generation_mode: DRAFT | ISSUE_AND_SEND}`; needs the `scheduled_invoices` feature. Failures emit `invoice.schedule_failed` and the invoice stays a draft.

### Standard vs simplified

- F1 `STANDARD` needs `recipient.nif` or `recipient.alternative_id`. A Spanish individual's name must match the census.
- F2 `SIMPLIFIED`: total including VAT up to 3,000 EUR (2,600 + 21 % = 3,146 is over); empty recipient (`recipient: {}`, BeeL fills consumidor final) only up to 400 EUR, between 400 and 3,000 the recipient needs `nif` or `alternative_id`; use F1 whenever the buyer is a business, asks for an identified invoice, or IRPF applies; forbidden with IRPF, recargo de equivalencia, reverse charge (ISP) or cross-border/OSS (`SIMPLIFICADA_FORBIDS_IRPF`, `_SURCHARGE`, `_ISP`, `_CROSS_BORDER`).

### Correcting and voiding

- `createCorrective($invoiceId, ...)` with `rectification_type`, `rectification_code`, `reason`, optional `lines` and `notes`. One corrective per original.
  - `PARTIAL`: difference lines (negative quantities to reduce); the original becomes `RECTIFIED`.
  - `TOTAL`: no lines (else `RECTIFICATIVA_TOTAL_CON_LINEAS`); the original becomes `VOIDED`.
  - Codes: `R1` error fundado en derecho, returns, discounts; `R2` concurso (insolvency); `R3` bad debt (incobrable); `R4` any other cause; `R5` only for F2 simplified invoices (R1 to R4 are not allowed on F2).
- `void($invoiceId, ...)`: `reason` of at least 10 characters, optional `void_date`. Only for invoices that should never have existed; the number is burned. BeeL sends the registro de anulación.
- Data errors on an issued invoice are always fixed with a corrective, or by voiding and reissuing.
- Correctives are numbered in the company's default corrective series (not the original's); without one: `422 SERIES_DEFAULT_NOT_FOUND`. Each corrective targets exactly one original; a second `TOTAL` is `409`. Only `ISSUED`, `SENT`, `PAID`, `OVERDUE` or `RECTIFIED` originals can be corrected or voided.
- F2 to F1 (canje): R5 `TOTAL` on the F2, then a new `STANDARD` invoice. F3 is not supported.
- Not supported (use the workaround): F3; autofactura and third-party issuance (each issuer uses its own BeeL account); standalone credit notes (always a corrective, R1 for returns/discounts); one corrective for several originals.

## VERI*FACTU status on an invoice

- Block `verifactu{enabled, submission_status, registration_number, registered_at, invoice_hash, qr_url, qr_base64, error_code, error_message}`.
- `submission_status`: `PENDING`, `ACCEPTED`, `REJECTED`, `VOIDED`, `NOT_SUBMITTED`. Only `REJECTED` requires action. Read `submission_status` before `error_code`, which can also be present on accepted-with-remarks submissions.
- `enabled: false` means the NIF is outside the regime, not a failure. There is no per-invoice opt-out and no "submit now" endpoint.
- Whether a company submits is taxpayer configuration (`verifactuConfiguration`, only `enabled` is writable; `nif_status` `ACTIVATED`/`DEACTIVATED`).
- `REJECTED` covers both an AEAT refusal (with `error_code`/`error_message`) and a submission BeeL gave up retrying (without them). Fix it with a corrective or void + reissue; there is no resubmit endpoint.
- `NOT_SUBMITTED` right after issuing is transient (re-read); if it persists, contact BeeL (it@beel.es) with the invoice ids.
- AEAT codes: `1101` issuing NIF not registered for VERI*FACTU, `3001` recipient NIF not in census (validate first), `4101` total mismatch, `4106` simplified over threshold (use F1), `5104` broken chain (contact BeeL), `2001` duplicate (no action).
- Transient AEAT errors are retried by BeeL. Subsanación (resubmitting the unchanged record) only fixes causes external to the invoice data (issuing NIF not yet censado, representation unsigned); it has no public endpoint and is not automatic: ask BeeL support.
- `skip_reason` is historical (per-invoice opt-outs that no longer exist); do not build logic on it.

## Series

- `format` must contain `{NUM}` or `{NUM:X}` (uppercase tokens). `counter_reset` defaults to `ANNUAL`; use `NEVER` if the format has no year token. The code is unique per company. The first series of a type becomes the default; `ensureDefaults()` guarantees one default each for STANDARD, SIMPLIFIED and CORRECTIVE.

## Recurring invoices

`frequency` `MONTHLY`/`QUARTERLY`/`YEARLY`; `day_of_month` (31 falls back to the month's last day); `max_invoices` 2 to 600; `draft_in_advance` (5-day window); `invoice_type` STANDARD/SIMPLIFIED. Status `ACTIVE`/`PAUSED`/`COMPLETED`, pause reason `USER`/`DOWNGRADE`/`GENERATION_FAILURE` (emits `recurring_invoice.paused`).

## PDF and email

- `getPdf()`: presigned URL valid for 5 minutes; waits up to 10 s (`Prefer: wait=N`, SDK `waitSeconds:`); 202 with `Retry-After` while rendering (SDK throws `BeelNotReadyError`). Drafts: `400 INVOICE_NOT_ISSUED_NO_PDF`, use `preview()` (image; documented as WebP, observed as PNG in the sandbox on 2026-09-27) or `previewPdf()` (drafts only). Never persist the URL.
- `send()` queues an email; bulk via `deliver()` (one email, several invoices). Sandbox only sends to the account holder's own address, otherwise `403 ENVIO_NO_PERMITIDO`.
- Email quotas (production / sandbox, rolling windows): 60/10 per hour, 300/30 per 24 h, 100 distinct recipients per 24 h, 10 sends of the same invoice. 429 means wait; 403 means not allowed.
- With `send_automatically`, email rejections do not surface on the create call; check the account email history (`$account->emails->list()`). Delivery status `QUEUED`/`SENT`/`DELIVERED`/`BOUNCED`/`OPENED`/`FAILED`/`REJECTED`.

## Taxes

- Every `NORMAL` line needs `main_tax{type, percentage, regime_key}`; it is never defaulted (`LINE_MAIN_TAX_REQUIRED`).
- Rates: `IVA` 0, 4, 5, 10, 21. `IGIC` (Canarias) 0, 3, 5, 7, 9.5, 15, 20. `IPSI` (Ceuta, Melilla) 0.5, 1, 2, 4, 8, 10. `OTHER`.
- A 0 % line needs `exemption_reason` (`EXEMPT_ZERO_RATE_REQUIRES_REASON`); with a reason the rate must be 0 and there can be no recargo de equivalencia.
- `exemption_reason` to AEAT code: `EXENTA_ART_20` E1, `EXENTA_ART_21` E2, `EXENTA_ART_22` E3, `EXENTA_ART_24` E4, `EXENTA_ART_25` E5 (needs `alternative_id.type = NIF_IVA`), `EXENTA_ART_26`/`EXENTA_ART_140` E6, `NO_SUJETA_ART_7_9` N1, `NO_SUJETA_LOCALIZACION` N2, `ISP_ART_84_2_A`/`_E`/`_F` S2 (reverse charge), `REGIMEN_ART_129`/`135`/`141`/`154`/`163_DECIES`, `OTRO` (needs `exemption_reason_text`). Omitted: S1 (subject and not exempt).
- `regime_key` (inside `main_tax`, defaults to `01`): `01` general, `02` export, `03` REBU (used goods, art), `04` investment gold, `05` travel agencies, `06` VAT group, `07` criterio de caja (every invoice of an opted-in taxpayer), `08` mixed IPSI/IVA/IGIC, `09`, `10`, `11` business premises rental, `14`, `15`, `17` OSS/IOSS, `18` recargo de equivalencia, `19` REAGYP / Canarias art. 25 Ley 19/1994, `20` módulos. `12`, `13` and `16` are not accepted.
- Enums above match the OpenAPI spec; the error codes come from BeeL's guides, and the spec lists only some of them (`SURCHARGE_REQUIRES_REGIME`, `REGIME_REQUIRES_SURCHARGE`, `SIMPLIFICADA_FORBIDS_IRPF`, `ALTERNATIVE_ID_INVALID`, `SERIES_DEFAULT_NOT_FOUND`), so treat unknown codes as possible and branch on `status` too.
- Cross-field rules BeeL enforces: `02` accepts only `EXENTA_ART_21` (`REGIME_REQUIRES_INCOMPATIBLE_EXEMPTION`); `18` requires `equivalence_surcharge_rate` (`REGIME_REQUIRES_SURCHARGE`) and a surcharge requires `18` (`SURCHARGE_REQUIRES_REGIME`, REBU `03` included); `17` with an `exemption_reason` is accepted but forces 0 % and drops the destination VAT, so never combine them; `ISP_*` with a surcharge is rejected (`ISP_INCOMPATIBLE_WITH_SURCHARGE`); `EXENTA_ART_25` requires a `NIF_IVA` recipient.
- REBU (`03`): the taxable base is the margin: `unit_price` = margin / 1.21 (sale 3,000, cost 2,000: base 826.45, VAT 173.55); BeeL relaxes the totals reconciliation for `03`.
- Territorial tax follows the place of supply, never the issuer's address: goods delivered or services used in the Canary Islands use `IGIC`, in Ceuta or Melilla `IPSI`, elsewhere in Spain `IVA`. Lines of one invoice may mix them. BeeL does not detect the place of supply.
- ISP (`ISP_ART_84_2_*`, S2) is a domestic Spanish mechanism (construction subcontracting `_F`, scrap `_E`, non-established sellers `_A`); never use it for EU or non-EU customers.
- Recargo de equivalencia pairs (strict): IVA 4 with 0.5, 5 with 0.625, 10 with 1.4, 21 with 5.2; `0` disables it on a line; 1.75 (tobacco) is not accepted. Apply it only when the buyer (a retailer) has declared in writing that they are in the regime, never inferred; it is per line (a transport fee on the same invoice stays `01`), never on exempt, not-subject or ISP lines, never on F2.
- IRPF: `irpf_rate` in {0, 1, 2, 7, 15, 19, 24}. Omitted means the company default applies; send `0` to withhold nothing. Company tax configuration: `apply_irpf`, `default_irpf_rate`, `irpf_exempt`, `apply_equivalence_surcharge`, `default_main_tax`.
- Pricing: exactly one of `unit_price` (4 decimals), `total_excluding_tax`, `total_including_tax` per line.
- Suplidos (disbursements): `line_type: SUPLIDO` with `source_invoice_reference` (required) and no tax fields; they add to `total_disbursements`/`total_to_pay` and are outside VERI*FACTU.
- Foreign recipients never go in `recipient.nif` (Spanish NIFs only): use `alternative_id{type, number, country_code}` with type `NIF_IVA` (EU VAT-ID, VIES-validated), `PASSPORT` (any country, ES included), `COUNTRY_ID`, `RESIDENCE_CERTIFICATE`, `OTHER_DOCUMENT` (non-ES only), or `NOT_REGISTERED` (ES only); breaking the country rules is `422 ALTERNATIVE_ID_INVALID`. Numeric codes `02`-`07` are deprecated aliases.
- BeeL validates `NIF_IVA` against VIES before submission and refuses the invoice if the VAT-ID is inactive: then identify the customer with `COUNTRY_ID`/`OTHER_DOCUMENT` and treat the sale as B2C.

### Classifying a line: who and where is the customer

| Customer | Selling | `exemption_reason` | `regime_key` | `main_tax.percentage` | `alternative_id.type` |
|---|---|---|---|---|---|
| Spain, normal | anything | omit (S1) | `01` | 4/5/10/21 (IGIC/IPSI by place) | none (`nif`) |
| Spain, reverse charge | construction, scrap, ... | `ISP_ART_84_2_F`/`_E`/`_A` (S2) | `01` | 0 | none (`nif`) |
| Spain, exempt by law | education, health, ... | `EXENTA_ART_20` (E1) + `exemption_reason_text` | `01` | 0 | none (`nif`) |
| Spain, not subject | samples, internal transfers | `NO_SUJETA_ART_7_9` (N1) | `01` | 0 | none (`nif`) |
| EU business with valid VIES VAT-ID | goods | `EXENTA_ART_25` (E5) | `01` | 0 | `NIF_IVA` |
| EU business with valid VIES VAT-ID | services | `NO_SUJETA_LOCALIZACION` (N2) | `01` | 0 | `NIF_IVA` |
| EU consumer, seller under the OSS threshold | anything | omit (S1) | `01` | Spanish rate | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |
| EU consumer, seller in OSS | anything | omit (regime `17` alone makes it N2) | `17` | destination country's rate (e.g. 19 DE) | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |
| Outside the EU (B2B or B2C) | goods (export) | `EXENTA_ART_21` (E2) | `02` | 0 | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |
| Outside the EU (B2B or B2C) | services | `NO_SUJETA_LOCALIZACION` (N2) | `01` | 0 | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |

- Goods vs services is decided per line, not per customer: an EU business buying both gets E5 and N2 lines on the same invoice. Intra-EU B2B services are N2, never ISP/S2.
- OSS threshold: 10,000 EUR per calendar year of cross-border B2C sales, aggregated across all EU countries (not per country or customer). Below it (and not opted in) bill Spanish VAT; once crossed or opted in, every cross-border B2C line of the year uses `17` with the destination rate, settled in Modelo 369. BeeL does not track the threshold: the app (or the taxpayer) must.
- Exports: keep the customs evidence (DUA) with the invoice.
- Any cross-border or foreign case needs `STANDARD` (F1): F2 is rejected for cross-border/OSS.

Tax treatment is the taxpayer's legal responsibility: when a case is not clearly covered here, surface it to a human (or the taxpayer's gestor) instead of guessing.

## Customers, products, NIF

Company-scoped CRUD, bulk (up to 500) and CSV import for customers; CRUD and bulk for products. `nif->validate()` returns syntax and census status; `NIF_NOT_IN_CENSUS` must not be retried in a loop.

## Stripe payment connections

Per company, via OAuth authorisation. Payments generate invoices automatically using BeeL's "Fiscal Mirror" rule (Stripe's amounts are mirrored, never recalculated). Events can be retried, drafted, resolved or discarded (`paymentConnections->events($id)`).

- Stripe invoices have no fiscal validity: configure them as `PROFORMA-` with emails off so customers only get BeeL's.
- IRPF is always 0 on Stripe-generated invoices; bill B2B clients who withhold through the API instead.
- Without Stripe Tax, the total is decomposed with the company's default tax as inclusive ("Prices include tax" per connection).
- F1 vs F2 per connection threshold (default 400, max 3,000 EUR); above it, NIF + address are required or the event is parked (`AMOUNT_REQUIRES_FISCAL_DATA`).
- Refunds/credit notes become correctives: F2 originals always R5; `duplicate`/`fraudulent` R4; anything else R1. EUR only.
- Goods vs services for cross-border classification comes from the Stripe product metadata or the connection default.
- Connection settings (threshold, auto-invoicing, email, customer creation) are dashboard-only, no API.

## Webhooks

- Subscriptions: `$account->webhooks->create(...)`; `account_relationship` `own` (default), `managed` or `all`. HTTPS only, max 10 subscriptions, last 50 delivery logs kept. The secret is shown once; rotating it invalidates the old one immediately (update the app's `BEEL_WEBHOOK_SECRET` at the same time). `test()` sends a signed synthetic event that is not retried.
- Envelope: `id`, `type`, `created_at`, `api_version`, `livemode` (deprecated), `test` (true only for dashboard test deliveries), `company_id` (route on this), `nif`, `account_id`, `account_external_ref`, `account_relationship` (`own`/`managed`), `data`. Null fields are omitted.
- Headers: `BeeL-Signature` (`t=<unix>,v1=<hex>`, HMAC-SHA256 of `t + "." + raw body`, 300 s window), `BeeL-Event`, `BeeL-Event-Id` (same on every retry, equals `id`), `BeeL-Delivery-Id`, `Idempotency-Key` (equals the event id).
- Delivery: answer 2xx within 10 s. 7 attempts on a fixed schedule (right away, then 1 min, 10 min, 1 h, 6 h, 24 h and 36 h after the previous one: the last about 67 h after the first; only the first 3, over 11 minutes, in sandbox) on 5xx, 408, 429, timeouts and connection errors. Other 4xx are not retried. Manual retry via `$account->webhooks->retryDelivery()` or the dashboard.
- Event `data`:
  - `invoice.issued`: `invoice_id`, `invoice_number`, `customer_email`, `customer_name`.
  - `invoice.email.sent`: `all_recipients`, `sent_at`.
  - `invoice.pdf.generated`: invoice id and number; fetch the PDF yourself.
  - `invoice.voided`: `cancellation_reason`.
  - `recurring_invoice.paused`: `reason` (`DOWNGRADE`/`GENERATION_FAILURE`), `blocker`, `since`.
  - `invoice.schedule_failed`: `scheduled_for`, `blocker`.
  - `verifactu.status.updated`: `previous_status`, `new_status`, `qr_url`, `qr_base64`, `invoice_hash`, `error_code`, `error_message`, `verifactu_registration_id`.
  - Provisioner only: `account.claimed`, `company.created`, `representation.signed` (`company_id`, `nif`, `signed_at`).
- Webhooks are a notification channel: on doubt, re-read the resource from the API (e.g. `$company->invoices->get($id)`) instead of trusting stale event data.

## Sources

https://docs.beel.es/llms.txt, https://docs.beel.es/llms-full.txt, https://docs.beel.es/api/openapi; pages `/verifactu`, `/verifactu/scope-and-limitations`, `/verifactu/invoice-types`, `/verifactu/cancel-and-fix`, `/verifactu/corrective-invoices`, `/verifactu/submission-states`, `/verifactu/tax-classification`, `/verifactu/international-customers`, `/verifactu/territorial-taxes`, `/verifactu/regime-keys`, `/verifactu/equivalence-surcharge`, `/verifactu/simplified-vs-standard`, `/verifactu/auto-submit`, `/verifactu/examples-cookbook`, `/guides/suplidos`, `/guides/glossary`, `/stripe/*`, `/auth`, `/guides/idempotency`, `/guides/handling-errors`, `/guides/rate-limits`, `/guides/sending-email`, `/multi-nif`, `/webhooks`, `/webhooks/retries`.
