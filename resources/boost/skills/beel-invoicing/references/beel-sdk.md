# lenorix/beel-sdk

Unofficial PHP SDK for the BeeL API (made by lenorix, not endorsed by BeeL). Mapped from the installed v0.3 source on 2026-09-26. `src/Generated` is Jane code generated from BeeL's OpenAPI spec; docs.beel.es and `https://docs.beel.es/api/openapi` are the source of truth for API behaviour.

In a Laravel app, obtain `Beel`, `CompanyScope` and `AccountScope` through `Lenorix\LaravelBeel\BeelManager` (see `laravel-package.md`), never with `new Beel(...)`.

## Conventions

- Resource methods unwrap BeeL's `{ "data": ... }` envelope and return Jane models with camelCase getters (`getId()`, `getInvoiceNumber()`, `getVerifactu()`). Many signatures say `mixed`; the real model is in the method's `@return` docblock.
- Request bodies are Jane models built with fluent setters (`(new CreateCustomerRequest())->setName(...)`), or with the builders below. Model classes live in `Lenorix\BeelSdk\Generated\Model`.
- `$query` arrays become query-string parameters (pagination `page`/`limit`, filters such as `external_ref`). Unknown query parameters are rejected by the API.
- Per-call options: every resource has `withOptions(new Lenorix\BeelSdk\Http\RequestOptions(idempotencyKey: ..., headers: [...]))`, returning a copy; sub-resources inherit them (`$company->withOptions(...)->invoices`). Applied by the transport, so they work on every operation; the same key is resent on automatic retries. `Authorization`, `Host`, `Content-Type` and `Content-Length` are rejected.
- Pagination: `list()` returns one page (`data` plus pagination metadata). Most listings also have a lazy generator `all(array $query = [])` (plus `allHistory()`, `allGrants()`, `allDeliveries()`) that fetches pages while you iterate, keeping filters and `limit`; `accounts->all()` follows `next_cursor`.

## Beel (entry point)

- Public properties: `raw` (generated client with every OpenAPI operation, e.g. `$beel->raw->getMyIdentity()`), `catalogs`, `nif`, `accounts`.
- `company(string $companyId): CompanyScope` and `account(string $accountId): AccountScope`. The argument is a UUID.
- Deprecated, do not use: properties `invoices`, `customers`, `products`, `series`, `configuration` and method `downloadPdf()` (legacy session-focused routes).

## Unscoped resources

- `me->identity()`: `MyIdentity` (account id, credential environment and scopes of the API key); `me->update(UpdateMeRequest)`.
- `catalogs->taxTypes()`, `->invoiceCustomizationOptions()`, `->updateMe(UpdateMeRequest)` (person preferences such as language).
- `nif->validate(string $nif): ?ValidateNifResponse`: syntax plus AEAT census status (`VALID`, `INVALID`, `PENDING`). A well-formed NIF is not necessarily registered.
- `accounts->list(array $query = [])`, `->provision(ProvisionAccountRequest)`, `->get(string $accountId)`, `->scope(string $accountId): AccountScope`. Listing and provisioning need privileged scopes granted by BeeL.

## CompanyScope

Methods: `get()`, `update(UpdateCompanyRequest)`, `delete()`, `fiscalSummary(array $query = [])` (e.g. `['year' => 2026]`), `issuingReadiness()` (`getReady()`, `getBlockers()`).

### invoices

| Method | Notes |
|---|---|
| `list(array $query = [])` | Paginated; filter e.g. `['external_ref' => ...]` |
| `create(CreateInvoiceRequest, array $query = [], array $headers = [])` | Draft unless `options.issue_directly`; query `wait_for_pdf` |
| `get(string $invoiceId)` | |
| `update(string $invoiceId, UpdateInvoiceRequest)` | Drafts only |
| `delete(string $invoiceId)` | Drafts only |
| `issue(string $invoiceId, array $query = [], array $headers = [])` | Definitive number; AEAT submission and PDF are asynchronous |
| `void(string $invoiceId, VoidInvoiceRequest, array $headers = [])` | `setReason()`, optional `setVoidDate()`; number never reused |
| `createCorrective(string $invoiceId, CreateCorrectiveInvoiceRequest, array $headers = [])` | `setRectificationType()`, `setRectificationCode()`, `setReason()`, optional `setLines()`, `setNotes()` |
| `setStatus(string $invoiceId, SetInvoiceStatusRequest, array $headers = [])` | Commercial status (e.g. PAID) |
| `getPdf(string $invoiceId)` | `InvoicePdfResponseData`: `getDownloadUrl()`, `getExpiresInSeconds()`, `getFileName()`; presigned URL, about 5 minutes |
| `preview(string $invoiceId)` | Draft PDF preview |
| `send(string $invoiceId, ?SendEmailRequest = null, array $headers = [])` | Queued, not delivered; check the account email history |
| `deliver(CreateInvoiceDeliveryRequest, array $headers = [])` | One email with several invoices |
| `derive(CreateInvoiceDerivationRequest, array $headers = [])` | New draft from an existing invoice |
| `convertToInvoice(string $invoiceId, ?ConvertProformaToInvoiceRequest = null, array $headers = [])` | Proforma to fiscal invoice |
| `createBatch(CreateInvoiceBatchRequest, array $headers = [])` | Bulk result |
| `createPdfArchive(CreateInvoicePdfArchiveRequest)`, `export(CreateInvoiceExportRequest)` | ZIP of PDFs, exports |
| `getSchedule`, `setSchedule(SetInvoiceScheduleRequest)`, `clearSchedule` | Scheduled issue (also `->schedule->get/set/clear`) |

Invoice model getters include `getId()`, `getNumber()`, `getInvoiceNumber()`, `getType()`, `getStatus()`, `getExternalRef()`, `getTotals()`, `getLines()`, `getRecipient()`, `getIssueDate()`, `getOperationDate()`, `getRectificationCode()`, `getRectificationType()`, `getRectifiedInvoiceId()`, `getVoidReason()`, and `getVerifactu()` (`getEnabled()`, `getSubmissionStatus()`, `getRegistrationNumber()`, `getRegisteredAt()`, `getInvoiceHash()`, `getQrUrl()`, `getErrorCode()`, `getErrorMessage()`, `getSkipReason()`).

### Other company resources

- `customers`: `list`, `create(CreateCustomerRequest, array $headers = [])`, `get`, `update(string $id, PatchCustomerRequest)`, `delete`, `createBulk`, `deleteBulk`, `import`, `previewImport`.
- `products`: `list`, `create(CreateProductRequest, array $headers = [])`, `get`, `update(string $id, PatchProductRequest)`, `delete`, `createBulk`, `deleteBulk`.
- `series`: `list`, `create(CreateSeriesRequest, array $headers = [])`, `get`, `update(string $id, PatchSeriesRequest)`, `delete`, `getDefaults()`, `setDefault(string $id, array $headers = [])`, `ensureDefaults(array $headers = [])`.
- `recurringInvoices`: `list`, `create(CreateRecurringInvoiceRequest)`, `get`, `update(string $id, PatchRecurringInvoiceRequest)`, `delete`, `setStatus(string $id, SetRecurringInvoiceStatusRequest)`, `nextOccurrence`, `history`, `stats`, `derive`, `generateNow`, `generate(string $id, array $headers = [])`, `skip`.
- `paymentConnections` (Stripe): `list`, `authorize(InitiatePaymentConnectionRequest)`, `update`, `disconnect`, `events(string $connectionId)` with `list(['needs_action' => true])`, `get`, `retry`, `draft`, `resolve`, `discard`, `restore`.
- `taxConfiguration`: `get()`, `update(UpdateTaxConfigurationRequest)`.
- `verifactuConfiguration`: `get()`, `update(UpdateVeriFactuConfigurationRequest)`. Sandbox companies always have VERI*FACTU enabled; enabling it in production requires a signed AEAT representation for the company.

## AccountScope

Methods: `get()`, `usage()`, `changeAccessLevel(ChangeAccessLevelRequest)`, `createClaimToken(?CreateClaimTokenRequest = null)`, `setOwner(SetAccountOwnerRequest)`, `endManagement()`.

- `companies`: `list(array $query = [])`, `create(CreateCompanyRequest)`, `stats(array $query = [])`.
- `members`: `list`, `get`, `update(string $id, ChangeMemberRoleRequest)`, `remove`, `listGrants(string $memberId)`, `putGrant(string $memberId, string $companyId, PutMemberGrantRequest)`, `removeGrant`.
- `invitations`: `list`, `create(CreateInvitationRequest)`, `get`, `revoke`.
- `webhooks`: `list`, `create(CreateWebhookSubscriptionRequest)`, `get`, `update`, `delete`, `test(string $id)`, `rotateSecret(string $id)`, `listDeliveries(string $id, array $query = [])`, `retryDelivery(string $webhookId, string $deliveryId)`, generators `all()` and `allDeliveries(string $id)`.
- `emails`: `list(array $query = [])`, `indicators(array $query = [])`, `get(string $emailId)`.

## Builders

- `Lenorix\BeelSdk\Builder\InvoiceBuilder`: `create()`, `type()`, `forCustomer(string $customerId)`, `operationDate()`, `dueDate()`, `series(string $seriesId)`, `externalRef()`, `metadata(array)`, `notes()`, `addLine(string $description, float $quantity, float $unitPrice, float $discountPercentage = 0)`, `addLineObject(CreateInvoiceRequestLinesItem)`, `build()`.
  - `build()` throws `LogicException` without a customer id or lines. It only supports a saved customer id; for inline recipients set `Recipient` on `CreateInvoiceRequest` directly.
  - `addLine()` sets no `main_tax`, which is invalid for `NORMAL` lines. Prefer `addLineObject()` with an explicit `CreateInvoiceRequestLinesItemMainTax` (`setType()`, `setPercentage()`, `setRegimeKey()`).
- Line setters (`CreateInvoiceRequestLinesItem`): `setLineType`, `setDescription`, `setQuantity`, `setUnit`, `setUnitPrice`, `setTotalExcludingTax`, `setTotalIncludingTax`, `setDiscountPercentage`, `setMainTax`, `setEquivalenceSurchargeRate`, `setIrpfRate`, `setExemptionReason`, `setExemptionReasonText`, `setSourceInvoiceReference`, `setSourceInvoiceIds`.
- `Lenorix\BeelSdk\Builder\CustomerBuilder`: `create()`, `name()`, `nif()`, `email()`, `phone()`, `notes()`, `address(street, number, postalCode, city, province, country, countryCode = 'ES')`, `build(): CreateCustomerRequest`.

## Errors

`Lenorix\BeelSdk\Exception\BeelApiError` (`statusCode`, `apiCode`, `details`, `requestId`, `retryAfter`) with subclasses `BeelAuthError` (401/403), `BeelNotFoundError` (404), `BeelConflictError` (409), `BeelValidationError` (422), `BeelRateLimitError` (429, `retryAfterSeconds`). Exceptions without an HTTP response (transport failures) are rethrown unchanged; in Laravel they are `LaravelNetworkException` / `LaravelClientException`. `WebhookVerificationError` is separate, with subclasses `WebhookHeaderError` (missing/malformed header), `WebhookTimestampError` (outside tolerance), `WebhookSignatureError` (no signature matches) and `WebhookPayloadError` (body not a JSON object / schema).

## Webhooks

- `Lenorix\BeelSdk\Webhook\WebhookEventType` cases: `VERIFACTU_STATUS_UPDATED` (`verifactu.status.updated`), `INVOICE_ISSUED` (`invoice.issued`), `INVOICE_EMAIL_SENT` (`invoice.email.sent`), `INVOICE_PDF_GENERATED` (`invoice.pdf.generated`), `INVOICE_VOIDED` (`invoice.voided`), `RECURRING_INVOICE_PAUSED` (`recurring_invoice.paused`), `INVOICE_SCHEDULE_FAILED` (`invoice.schedule_failed`), `ACCOUNT_CLAIMED` (`account.claimed`), `COMPANY_CREATED` (`company.created`), `REPRESENTATION_SIGNED` (`representation.signed`).
- `WebhookVerifier(string $secret, int $toleranceSeconds = 300)`: `verify(string $rawBody, ?string $signatureHeader, ?int $now = null): array`; `verifyEvent(...)`: typed `WebhookEvent` with per-type `data` models. Step by step: `WebhookSignatureHeader::parse($header)`, then `$verifier->checkTimestamp($parsed)` and `->checkSignature($body, $parsed)` (reject junk before hashing). `WebhookSigner($secret)->sign($body, ?$timestamp)` builds a header the way BeeL does (tests, local). The package controller already does all this for you.
