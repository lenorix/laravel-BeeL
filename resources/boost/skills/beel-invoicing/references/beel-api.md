# BeeL API behaviour

Summarised from https://docs.beel.es (llms-full.txt and the OpenAPI spec at https://docs.beel.es/api/openapi) on 2026-09-26; the invoice, tax, VERI*FACTU, series, recurring and webhook sections were rechecked against the guides, the changelog up to 2026-09-28 and the current spec (as used by `@beel_es/sdk` 2.2.0) on 2026-09-30. Limits, codes and dates change: when a detail matters, confirm it at the source. JSON field names below are snake_case; SDK models expose them as camelCase getters and setters.

## What BeeL covers

- Covers: numbering from series, freezing totals at issue, the VERI*FACTU hash chain and signed registro de facturación, asynchronous submission to the AEAT with retries on transient errors, the registro de anulación when voiding, QR data (`verifactu.qr_url`, `qr_base64`), PDF rendering and templates, email delivery, NIF census validation, recurring invoices, Stripe auto-invoicing.
- BeeL computes bases, taxes and totals from the lines it receives (only Stripe auto-invoicing mirrors the amounts it gets).
- The integrator decides `type`, `rectification_code`/`rectification_type`, per-line `exemption_reason`, `regime_key`, tax type and rate, and recipient identification.
- Not supported: autofactura or invoices issued by third parties, standalone abonos (always a corrective), a public subsanación endpoint, one corrective covering several originals, regime keys `03` (REBU), `06`, `12`, `13`, `14`, `16`, exemptions `EXENTA_ART_26` and `REGIMEN_ART_*`, reverse charge `ISP_ART_84_2_G`, correcting or voiding an F3.
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

- Header `Idempotency-Key` exactly (other spellings are ignored), on POST only. Letters, digits, `-`, `_`; max 255 chars. Stored 24 h, per user and environment, bound to path and body. 2xx and 5xx responses are stored (a 5xx replays for the same key: check whether the work happened, then use a new key); a 4xx is not, so it frees the key.
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

- Types: `STANDARD` (AEAT F1), `SIMPLIFIED` (F2), `CORRECTIVE` (R1 to R5, created only via the corrective endpoint), `PROFORMA` (non-fiscal, never sent to the AEAT, no `verifactu` block). An exchange of simplified invoices issues a `STANDARD` invoice recorded as F3.
- Statuses: `SCHEDULED`, `DRAFT`, `ISSUED`, `SENT`, `PAID`, `RECTIFIED` (one or more PARTIAL correctives), `VOIDED` (with `void_cause`: `VOID_REQUEST`, `TOTAL_CORRECTIVE` or `EXCHANGED`), `CONVERTED`, `ACTIVE`, `EXPIRED` (proformas). `OVERDUE` is reserved: nothing sets it; compare `due_date` with today yourself.
- `issue_date` is always today (set by the server, anti-fraud law); record an earlier operation with `operation_date` (not in the future, at most 20 years old), and use scheduling for a future issue date.
- Create body: `type`, `recipient` and `lines` are required. Optional: `series_id` (must be a series of the same document type, `SERIES_INCOMPATIBLE_DOC_TYPE`; the default series of the type otherwise, created on first use for simplified and corrective), `operation_date`, `due_date` (not in the past), `valid_until` (proformas only, informational), `payment_info{method, iban, swift, payment_term_days}`, `notes` (max 1,000 chars), `external_ref`, `metadata`, `options{issue_directly, wait_for_pdf, send_automatically, email_config, attach_source_invoices}`.
- `payment_info.method`: `NONE`, `BANK_TRANSFER` (default, needs `iban`), `CARD`, `CASH`, `CHECK`, `DIRECT_DEBIT`, `BIZUM`, `OTHER`.
- Recipient: either `customer_id` alone or inline data, never both (`RECIPIENT_CUSTOMER_AND_DATA_EXCLUSIVE`). Inline: `legal_name` (max 120 characters), `trade_name`, `nif` (Spanish only) or `alternative_id`, `address{street, number, floor, door, postal_code, city, province, country, country_code}` (`province` required only in Spain), `phone`, `email`. An F1 needs a name and an address (`RECIPIENT_ADDRESS_REQUIRED`).
- Lines: `quantity` (never 0; negative only on correctives), `description`, `unit`, exactly one price (`unit_price` up to 4 decimals, `total_excluding_tax` or `total_including_tax`; `LINE_UNIT_PRICE_XOR_DECLARED_TOTAL`), `discount_percentage` 0 to 100 (only with `unit_price`: `LINE_DECLARED_TOTAL_FORBIDS_DISCOUNT`; there is no invoice-level discount), `main_tax`, `exemption_reason`, `equivalence_surcharge_rate`, `irpf_rate`, `line_type` `NORMAL`/`SUPLIDO`. At least one `NORMAL` line (`INVOICE_REQUIRES_AT_LEAST_ONE_NORMAL_LINE`). Everything is in EUR.
- Totals: BeeL computes bases, taxes and totals from the lines (the Stripe "Fiscal Mirror" is the only case that mirrors amounts). Tax is rounded half-up per tax group, not per line; with `total_including_tax` the last tax absorbs the leftover cent. Response totals: `taxable_base`, `total_vat` (every indirect tax), `total_equivalence_surcharge`, `total_irpf`, `invoice_total`, `total_discounts`, `total_disbursements`, `total_to_pay`, and `vat_breakdown`/`surcharge_breakdown`/`irpf_breakdown`. Only a corrective may total below zero. The amount the AEAT registers (and the QR `importe`) leaves out IRPF, suplidos and OSS VAT, so it can differ from the invoice total.
- Checked before numbering (the AEAT limits): at most 12 tax groups (`INVOICE_TAX_BREAKDOWN_TOO_LONG`), rates with at most 2 decimals, invoice number at most 60 characters without `" ' < > =` (`INVOICE_NUMBER_TOO_LONG`), recipient name at most 120 (`FIELD_TOO_LONG`).
- Issue is irreversible and assigns the number (`400 SERIES_NUMBER_COLLISION` if the number is taken). With VERI*FACTU enabled the recipient's census status is checked before numbering: an uncensused recipient returns 422 and no number is consumed. AEAT submission and PDF generation happen asynchronously after the 2xx. An invoice whose `total_to_pay` is 0 is issued as `PAID`.
- Editable (`PATCH`): `DRAFT`, `SCHEDULED` and `ACTIVE` proformas; anything else is `STATUS_NOT_MODIFIABLE`. The type may change only between STANDARD and SIMPLIFIED; nullable fields sent as `null` clear the stored value. Issued invoices are immutable.
- Commercial status (`setStatus`): `PAID` from `ISSUED` or `SENT`, `SENT` from `ISSUED`, `ISSUED` only to undo `SENT`. Mark an original `PAID` before a PARTIAL corrective: `RECTIFIED` can never become `PAID`. Batch: `createBatch()` with `operation` `ISSUE` or `STATUS` (`new_status` `SENT`/`PAID`, `payment_date`) for up to 50 invoices.
- Scheduling: `PUT .../schedule` with both `scheduled_for` (today or later, `SCHEDULED_DATE_IN_PAST`; it becomes the issue date) and `generation_mode: DRAFT | ISSUE_AND_SEND`; also GET and DELETE. Scheduled invoices are processed in a daily early-morning run (Madrid time). Needs the `scheduled_invoices` feature. Failures emit `invoice.schedule_failed` and the invoice stays a draft.
- Proformas: born `ACTIVE` and numbered, need a full recipient, ignore `issue_directly`; `EXPIRED` is derived from `valid_until`. `convertToInvoice()` (`POST .../convert-to-invoice {issue}`) atomically creates a `STANDARD` draft or issued invoice linked by `source_proforma_id`/`converted_invoice_id`; only once (`409 PROFORMA_ALREADY_CONVERTED`); deleting the converted draft makes the proforma `ACTIVE` again. Voiding an `ACTIVE` proforma is non-fiscal.
- Derivations: `derive()` (`POST .../invoices/derivations {from_invoice_id, mode: DUPLICATE, series_id?, notes?}`) copies an invoice into a new draft (a copy of a corrective is born `STANDARD`); `recurringInvoices->derive()` builds a template from an invoice.

### Standard vs simplified

- F1 `STANDARD` needs `recipient.nif` or `recipient.alternative_id`, a name and an address. A Spanish individual's name must match the census.
- F2 `SIMPLIFIED` never identifies its recipient: a `nif` or `alternative_id` (also through a `customer_id` whose customer has one) is `422 SIMPLIFIED_INVOICE_FORBIDS_IDENTIFIED_RECIPIENT`, whatever the amount. Send `recipient: {}`.
- BeeL enforces only the 3,000 EUR cap including VAT (`SIMPLIFIED_INVOICE_EXCEEDS_LEGAL_LIMIT`; 2,600 + 21 % = 3,146 is over). The legal general limit is 400 EUR: above it, up to 3,000, an F2 is lawful only for the activities of art. 4.2 RD 1619/2012 (retail, hospitality, transport, ...), which BeeL does not check.
- Use F1 whenever the buyer is a business, asks for an identified invoice, or IRPF, recargo de equivalencia or reverse charge applies. An F2 may use OSS regime `17`, but not `EXENTA_ART_25` or `NO_SUJETA_LOCALIZACION`. An omitted `irpf_rate` is 0 on an F2.
- F2 to F1 (canje): `createSimplifiedExchange()` (`POST .../invoices/simplified-exchanges`, up to 50 simplified ids) issues a `STANDARD` invoice recorded as F3 and voids the F2s (`void_cause: EXCHANGED`). All or nothing. Each F2 must be already accepted by the AEAT (`EXCHANGE_SIMPLIFIED_NOT_YET_ACCEPTED`: retry after `verifactu.status.updated`; `EXCHANGE_SIMPLIFIED_RECORD_REJECTED`), issued with VERI*FACTU (`SIMPLIFIED_EXCHANGE_NOT_RECORDABLE` names one that was not), of the same company and environment (`SIMPLIFIED_NOT_EXCHANGEABLE`), and listed once (`EXCHANGE_DUPLICATED_SIMPLIFIED`). An F3 cannot be corrected (`EXCHANGE_INVOICE_NOT_CORRECTABLE`) or voided yet. Do not use an R5 for a canje. Behaviour as of 2026-09-28 (changelog "Simplified invoice exchanges are recorded with VeriFactu").

### Correcting and voiding

- `createCorrective($invoiceId, ...)` with `rectification_type`, `rectification_code`, `reason`, optional `lines`, `notes`, `series_id`, `external_ref` (exempt from uniqueness), `metadata`, `options`, `circumstance_date`, `recipient`, `recipient_is_business`. A corrective is issued at once (no draft).
  - `PARTIAL`: difference lines (negative quantities to reduce); the original becomes `RECTIFIED`. Several PARTIAL correctives per original are allowed; none may take a rate's total below zero (`CORRECTIVE_EXCEEDS_INVOICED_AMOUNT`). Per-line surcharge and IRPF are inherited from the original.
  - `TOTAL`: no lines; it negates the original plus its live correctives, and the original becomes `VOIDED` (`void_cause: TOTAL_CORRECTIVE`). A second one is `422 INVOICE_NOT_CORRECTIBLE_IN_CURRENT_STATUS`.
  - Codes: `R1` error fundado en derecho, returns, discounts; `R2` concurso (insolvency); `R3` bad debt (incobrable); `R4` any other cause; `R5` only for F2 simplified invoices (R1 to R4 are not allowed on F2).
  - Deadline: 4 years from `circumstance_date` (the date of the circumstance that justifies it; not with R4) or the original's date (`CORRECTIVE_OUT_OF_TIME`). R3 needs 6 months (`CORRECTIVE_BAD_DEBT_TOO_EARLY`) and `recipient_is_business` when the base is 50 EUR or less; R2 and R3 need a recipient established in Spain (R2 also elsewhere in the EU).
  - Fixing the recipient's data: `PARTIAL` + `R4`, no lines, and `recipient`. A withholding (IRPF) error is not corrected: void and reissue (`CORRECTIVE_WITHHOLDING_ONLY`).
  - Not correctable: a corrective (`CORRECTIVE_NOT_RECTIFIABLE`), an original whose VERI*FACTU record was rejected (`CORRECTIVE_ORIGINAL_RECORD_REJECTED`: void and reissue), an F3 exchange invoice.
- `void($invoiceId, ...)`: `reason` (10 to 500 characters); `issued_in_error: true` is required once the invoice was sent or paid (`VOID_REQUIRES_ISSUED_IN_ERROR`); `void_date` is deprecated and ignored (the server sets `voided_at`). Only for invoices that should never have existed; the number is burned and BeeL sends the registro de anulación. Not possible with live correctives (`INVOICE_HAS_LIVE_CORRECTIVES`) or on a TOTAL corrective (`TOTAL_CORRECTIVE_NOT_VOIDABLE`).
- Correctives are numbered in the company's default corrective series (not the original's), created on first use (code `R`); a series of another type is `SERIES_INCOMPATIBLE_DOC_TYPE`. Only `ISSUED`, `SENT`, `PAID` or `RECTIFIED` originals can be corrected or voided.
- An issued invoice's PDF never changes, even after a void or a corrective.
- Not supported: autofactura and third-party issuance (each issuer uses its own BeeL account); standalone credit notes (always a corrective, R1 for returns and discounts); one corrective for several originals.

## VERI*FACTU status on an invoice

- Block `verifactu{enabled, submission_status, registration_number, registered_at, invoice_hash, qr_url, qr_base64, error_code, error_message}`, absent on proformas. `qr_url`, `qr_base64` and `invoice_hash` are present from `PENDING`; `registration_number` is a BeeL id, not an AEAT code. `listVerifactuRecords()` (`GET .../invoices/{id}/verifactu-records`) lists each record (`operation` `REGISTRATION` or `VOID`) with its own status; a record id equals the webhook's `verifactu_registration_id`, and `verifactu.status.updated` carries the same `operation`.
- `submission_status`: `PENDING` (also while BeeL retries a transient AEAT error, without `error_code`), `ACCEPTED`, `REJECTED`, `VOIDED`, `NOT_SUBMITTED`. Only `REJECTED` requires action. Read `submission_status` before `error_code`, which can also be present on accepted-with-remarks submissions.
- `enabled: false` means the NIF is outside the regime, not a failure. There is no per-invoice opt-out and no "submit now" endpoint. Whether a company submits is taxpayer configuration (`verifactuConfiguration`, only `enabled` is writable; `nif_status` `ACTIVATED`/`DEACTIVATED`).
- `REJECTED` with an `error_code`: the AEAT refused it. Without one: refused before reaching the AEAT, BeeL stopped waiting for an answer, or the AEAT already holds another invoice with this number (reissue in another series); `error_message` says which (BeeL's own text). A rejected invoice is not registered: void it (nothing is sent to the AEAT) and reissue; a corrective is refused (`CORRECTIVE_ORIGINAL_RECORD_REJECTED`). Its PDF may be unavailable (`400 INVOICE_NOT_REGISTERED_NO_PDF`).
- AEAT codes: `1109`/`1110` a NIF on the invoice not in the census (validate recipients first), `1123` wrong NIF format, `1193` recipient NIF missing or equal to the issuer's, `4104`/`4107` issuer NIF not in the census, `4109` issuer NIF format, `4112` submission not authorised for this NIF (representation), `4141` AEAT suspended submission access, `3000` duplicate (do not reissue: contact BeeL), `3001` record already cancelled (on a void: nothing to redo). https://docs.beel.es/verifactu/handling-rejections
- Reconcile, do not only listen: re-read invoices still `PENDING` or `NOT_SUBMITTED` periodically. `NOT_SUBMITTED` right after issuing is transient (re-read); if it persists, contact BeeL (it@beel.es) with the invoice ids.
- Subsanación (resubmitting the unchanged record) only fixes causes external to the invoice data (issuing NIF not yet censado, representation unsigned); it has no public endpoint and is not automatic: ask BeeL support.
- `skip_reason` is historical (per-invoice opt-outs that no longer exist); do not build logic on it.

## Series

- `document_type` is required: a series numbers only its type (`SERIES_INCOMPATIBLE_DOC_TYPE`). `format` must contain `{NUM}` or `{NUM:X}` (uppercase tokens). `counter_reset` defaults to `ANNUAL` (the format needs a year token; `MONTHLY` needs month and year); use `NEVER` without date tokens. `initial_number` applies to the first period only. A format that could print a number another series prints is `409 SERIES_FORMAT_OVERLAPS`.
- The code is unique per company. The first series of a type becomes the default; `ensureDefaults()` guarantees one default each for STANDARD, SIMPLIFIED and CORRECTIVE, and the simplified and corrective defaults are also created on first use. `numbering_locked` turns true at the first issue: `code`, `format`, `counter_reset` and `initial_number` can no longer change (create a new series). Every issued number stays consumed, voided or not.

## Recurring invoices

- `series_id` is required. `frequency` `MONTHLY`/`QUARTERLY`/`YEARLY` (default `MONTHLY`); `day_of_month` (31 falls back to the month's last day); either `end_date` or `max_invoices` (2 to 600), not both (`RECURRING_END_MODE_CONFLICT`); `draft_in_advance` (5-day window); `invoice_type` STANDARD/SIMPLIFIED. Template lines use flat tax fields (`vat_rate`, ...), not `main_tax`; an omitted `irpf_rate` is 0.
- `send_automatically` needs a recipient it can resolve (`email_configuration.recipients` or the customer's email): otherwise `422 SIN_DESTINATARIO_RESOLUBLE`.
- Status `ACTIVE`/`PAUSED`/`COMPLETED`, pause reason `USER`/`DOWNGRADE`/`GENERATION_FAILURE` (emits `recurring_invoice.paused`); `is_failing` flags a template whose last generation failed; history entries have `type` `GENERATED`/`FAILED`/`SKIPPED`/`PAUSED`.

## PDF and email

- `getPdf()`: presigned URL valid for 5 minutes; waits up to 10 s (`Prefer: wait=N`, SDK `waitSeconds:`); 202 with `Retry-After` while rendering (SDK throws `BeelNotReadyError`). Drafts: `400 INVOICE_NOT_ISSUED_NO_PDF`, use `preview()` (image; documented as WebP, observed as PNG in the sandbox on 2026-09-27; for an issued invoice it answers 202 until the PDF exists) or `previewPdf()` (drafts only). A registration rejected before reaching the AEAT has no PDF (`400 INVOICE_NOT_REGISTERED_NO_PDF`). Never persist the URL.
- `send()` queues an email (202 while the PDF does not exist yet); bulk via `deliver()` (one email, several invoices). Drafts cannot be sent (`INVOICE_DRAFT_NOT_SENDABLE`). Recipients: `recipients`, else `email_config`, else the customer's `billing_emails`, else its `email` (`INVOICE_EMAIL_NO_RECIPIENTS`); reserved domains (`example.com`, `.test`) are refused. Emailing invoices needs the recipient's consent. Sandbox only sends to the account holder's own address, otherwise `403 ENVIO_NO_PERMITIDO`.
- Email quotas (production / sandbox, rolling windows): 60/10 per hour, 300/30 per 24 h, 100 distinct recipients per 24 h, 10 sends of the same invoice. 429 means wait; 403 means not allowed.
- With `send_automatically`, email rejections do not surface on the create call; check the account email history (`$account->emails->list()`). Delivery status `QUEUED`/`SENT`/`DELIVERED`/`BOUNCED`/`OPENED`/`FAILED`/`REJECTED`.

## Taxes

Rules as of BeeL's changelog of 2026-09-27 ("rates judged by operation date"): BeeL now refuses before numbering what the AEAT would refuse after issue. Correctives keep what their original carried.

- Every `NORMAL` line needs `main_tax{type, percentage, regime_key}`; it is never defaulted (`LINE_MAIN_TAX_REQUIRED`).
- Rates: `IVA` 0, 4, 10, 21, plus temporary rates judged on `operation_date` (or the issue date): 5 only for 2022-07-01 to 2024-09-30, 2 and 7.5 only for 2024-10-01 to 2024-12-31 (`VAT_RATE_NOT_ACCEPTED_ON_DATE`). `IGIC` (Canarias) 0, 3, 5, 7, 9.5, 15, 20. `IPSI` (Ceuta, Melilla) 0.5, 1, 2, 4, 8, 10. `OTHER`. At most 2 decimals.
- A 0 % line needs `exemption_reason` (`EXEMPT_ZERO_RATE_REQUIRES_REASON`); with a reason the rate must be 0 and there can be no recargo de equivalencia.
- `exemption_reason` to AEAT code: `EXENTA_ART_20` E1 (education, health, ...), `EXENTA_ART_21` E2 (exports of goods), `EXENTA_ART_22` E3 (operations treated as exports, e.g. international transport), `EXENTA_ART_24` E4 (free zones, customs regimes), `EXENTA_ART_25` E5 (intra-EU supplies of goods; needs `alternative_id.type = NIF_IVA`), `EXENTA_ART_140` E6 (investment gold, usually regime `04`), `NO_SUJETA_ART_7_9` N1 (e.g. transfer of a going concern), `NO_SUJETA_LOCALIZACION` N2 (place of supply outside Spain, arts. 69-70), `OTRO` (needs `exemption_reason_text`). Omitted: S1 (subject and not exempt).
- Reverse charge (S2, «inversión del sujeto pasivo», printed by BeeL), art. 84.Uno.2.º LIVA: `ISP_ART_84_2_A` supplier not established in Spain, `_B` unwrought or semi-finished gold, `_C` scrap, waste and recovery materials, `_D` greenhouse gas emission allowances, `_E` certain real estate supplies (insolvency, waived exemption, enforced security), `_F` construction or renovation works. `_G` (silver, platinum, mobile phones, consoles, laptops, tablets) is refused (`REVERSE_CHARGE_CASE_NOT_SUPPORTED`: the law requires a special series). ISP is a domestic mechanism: never use it for EU or non-EU customers.
- Refused exemptions: `EXENTA_ART_26` (it exempts the buyer's intra-EU acquisition, not a supply: use `EXENTA_ART_25`; `EXEMPTION_NOT_FOR_ISSUED_INVOICE`) and every `REGIMEN_ART_*` (declare the regime with `regime_key` instead; `EXEMPTION_REGIME_NOT_SUPPORTED_IN_VERIFACTU`).
- `regime_key` (inside `main_tax`, defaults to `01`): `01` general (also IGIC's general regime), `02` exports (with `EXENTA_ART_21` or `EXENTA_ART_22`; IVA and IGIC, not IPSI), `04` investment gold (only with reverse charge or an exemption), `05` travel agencies (mention on the PDF), `07` criterio de caja (every invoice of an opted-in taxpayer; mention on the PDF; no reverse charge, not-subject or exemption other than `EXENTA_ART_20`/`OTRO`), `08` operation subject to another indirect tax (IPSI or IGIC on an IVA line, IPSI or IVA on an IGIC line; only with `NO_SUJETA_LOCALIZACION` at 0 %), `09` mediating travel agencies, `10` third-party collections (only `STANDARD`, a recipient `nif`, and `NO_SUJETA_ART_7_9`), `11` business premises rental (21 % only, no reverse charge), `15` successive-tract operations, `17` OSS/IOSS, `18` recargo de equivalencia, `19` REAGYP or Canarias art. 25 Ley 19/1994, `20` módulos. Refused (`REGIME_KEY_NOT_SUPPORTED`): `03` REBU (the invoice must not show the tax, and BeeL's always does), `06` VAT group, `14` public works certifications; `12`, `13` and `16` do not exist in the enum. Errors: `REGIME_KEY_CLASSIFICATION_NOT_ACCEPTED`, `REGIME_KEY_REQUIRES_VAT_RATE`, `REGIME_KEY_REQUIRES_STANDARD_INVOICE`, `REGIME_KEY_REQUIRES_RECIPIENT_NIF`. https://docs.beel.es/verifactu/regime-keys
- Cross-field rules BeeL enforces: `02` needs `EXENTA_ART_21` or `EXENTA_ART_22` (`REGIME_REQUIRES_INCOMPATIBLE_EXEMPTION`); send `02` explicitly with those exemptions (one page says `01` is refused with `EXEMPTION_INCOMPATIBLE_WITH_REGIME`, the 2026-09-21 changelog says it is stored as `02`). `18` requires `equivalence_surcharge_rate` (`REGIME_REQUIRES_SURCHARGE`) and a surcharge requires `18` (`SURCHARGE_REQUIRES_REGIME`); send `18` explicitly (a `01` with a surcharge may be rewritten to `18`; send `equivalence_surcharge_rate: 0` to keep `01`). `17` with an `exemption_reason` is accepted but forces 0 % and drops the destination VAT, so never combine them; reverse charge with a surcharge is rejected.
- Enums above match the OpenAPI spec; the error codes come from BeeL's guides and changelog, and the spec lists only some of them, so treat unknown codes as possible and branch on `status` too.
- Territorial tax follows the place of supply, never the issuer's address: goods delivered or services used in the Canary Islands use `IGIC`, in Ceuta or Melilla `IPSI`, elsewhere in Spain `IVA`. Lines of one invoice may mix them. BeeL does not detect the place of supply.
- Recargo de equivalencia pairs (strict, by operation date): IVA 21 with 5.2 (1.75 for tobacco), 10 with 1.4, 4 with 0.5, 5 with 0.62 (2023-01-01 to 2024-09-30; 0.5 before; `0.625` is a validation error), 2 with 0.26 and 7.5 with 1 (Q4 2024). A pair outside its period is `SURCHARGE_RATE_NOT_ACCEPTED_ON_DATE`. `0` disables it on a line. Apply it only when the buyer (a retailer) has declared in writing that they are in the regime, never inferred; never on exempt, not-subject or reverse-charge lines, never on F2. The law (RD 1619/2012 art. 16.4, BeeL rule SUR-002) requires supplies with the surcharge on their own invoice, even though BeeL accepts mixed invoices: invoice anything else (e.g. a transport fee) separately.
- IRPF: `irpf_rate` in {0, 1, 2, 2.8, 6, 7, 7.6, 9.5, 15, 19, 24} (2.8, 6, 7.6 and 9.5 for Ceuta and Melilla), limited by the issuer's NIF: entities (`A`, `B`, `C`, `D`, `F`, `G`, `Q`, `R`, `U`, `W`) only 0, 19, 24 and 9.5; `N` only 0, 19 and 24; `S` and `P` only 0 (`IRPF_RATE_NOT_FOR_CORPORATE_ISSUER`). Omitted means the company default applies (0 on F2 and recurring templates); send `0` to withhold nothing. Company tax configuration: `apply_irpf`, `default_irpf_rate`, `irpf_exempt`, `apply_equivalence_surcharge`, `default_main_tax`, `withholding_options`.
- Suplidos (disbursements): `line_type: SUPLIDO` with `source_invoice_reference` (required) and no tax fields (`LINE_SUPLIDO_MUST_HAVE_NO_TAX`); `source_invoice_ids` consolidates them; they add to `total_disbursements`/`total_to_pay` and are outside VERI*FACTU. An invoice of only suplidos is refused.
- Foreign recipients never go in `recipient.nif` (Spanish NIFs only): use `alternative_id{type, number, country_code}` with type `NIF_IVA` (EU VAT-ID, not ES, with its country prefix such as `EL`: `ALTERNATIVE_ID_VAT_REQUIRES_EU_COUNTRY`, `_VAT_INVALID_FORMAT`), `PASSPORT` (any country, ES included), `COUNTRY_ID`, `RESIDENCE_CERTIFICATE`, `OTHER_DOCUMENT` (non-ES only), or `NOT_REGISTERED` (ES only, a DNI or NIE); `country_code` is required except for `PASSPORT` and `NOT_REGISTERED` (`ALTERNATIVE_ID_COUNTRY_REQUIRED`); breaking the country rules is `422 ALTERNATIVE_ID_INVALID`. Numeric codes `02`-`07` are deprecated aliases.
- BeeL checks only the format of a `NIF_IVA`: a number not active in VIES is issued and then ends `REJECTED` by the AEAT. Check VIES yourself before issuing; if it is inactive, identify the customer with `COUNTRY_ID`/`OTHER_DOCUMENT` and treat the sale as B2C.
- Business customers must be invoiced before the 16th of the month after the operation (rule DAT-006).

### Classifying a line: who and where is the customer

| Customer | Selling | `exemption_reason` | `regime_key` | `main_tax.percentage` | `alternative_id.type` |
|---|---|---|---|---|---|
| Spain, normal | anything | omit (S1) | `01` | 4/5/10/21 (IGIC/IPSI by place) | none (`nif`) |
| Spain, reverse charge | construction, scrap, ... | `ISP_ART_84_2_F`/`_C`/... (S2) | `01` | 0 | none (`nif`) |
| Spain, exempt by law | education, health, ... | `EXENTA_ART_20` (E1) + `exemption_reason_text` | `01` | 0 | none (`nif`) |
| Spain, not subject | samples, internal transfers | `NO_SUJETA_ART_7_9` (N1) | `01` | 0 | none (`nif`) |
| EU business with valid VIES VAT-ID | goods | `EXENTA_ART_25` (E5) | `01` | 0 | `NIF_IVA` |
| EU business with valid VIES VAT-ID | services | `NO_SUJETA_LOCALIZACION` (N2) | `01` | 0 | `NIF_IVA` |
| EU consumer, seller under the OSS threshold | anything | omit (S1) | `01` | Spanish rate | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |
| EU consumer, seller in OSS | anything | omit (regime `17` alone makes it N2) | `17` | destination country's rate (e.g. 19 DE) | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |
| Outside the EU (B2B or B2C) | goods (export) | `EXENTA_ART_21` (E2) | `02` (send it explicitly) | 0 | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |
| Outside the EU (B2B or B2C) | services | `NO_SUJETA_LOCALIZACION` (N2) | `01` | 0 | `PASSPORT`/`COUNTRY_ID`/`OTHER_DOCUMENT` |

- Goods vs services is decided per line, not per customer: an EU business buying both gets E5 and N2 lines on the same invoice. Intra-EU B2B services are N2, never ISP/S2.
- OSS threshold: 10,000 EUR per calendar year of cross-border B2C sales, aggregated across all EU countries (not per country or customer). Below it (and not opted in) bill Spanish VAT; once crossed or opted in, every cross-border B2C line of the year uses `17` with the destination rate, settled in Modelo 369. BeeL does not track the threshold: the app (or the taxpayer) must.
- Exports: keep the customs evidence (DUA) with the invoice.
- An identified foreign customer needs `STANDARD` (F1): an F2 identifies no recipient and refuses `EXENTA_ART_25` and `NO_SUJETA_LOCALIZACION`. OSS B2C (`17`) is allowed on an F2.

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
