---
name: beel-invoicing
description: Build Spanish invoicing features on BeeL with lenorix/laravel-beel and lenorix/beel-sdk. Use for invoices (facturas) and drafts, issuing, corrective invoices (rectificativas R1-R5), voiding (anulación), simplified invoices, proformas, series and numbering, recurring invoices, customers, products, taxes (IVA, IGIC, IPSI, IRPF, recargo de equivalencia, exemptions, regime keys), foreign and EU customers, VERI*FACTU and AEAT submission status and QR codes, NIF validation, BeeL accounts, companies and AEAT representation, invoice PDFs and emails, and BeeL webhooks.
license: MIT
metadata:
  author: lenorix
---

# BeeL invoicing

## Overview

BeeL (https://docs.beel.es) is a REST API for Spanish invoicing with VERI*FACTU built in. `lenorix/laravel-beel` wires the unofficial `lenorix/beel-sdk` into Laravel: clients from config or runtime credentials, Laravel's HTTP client as transport, and a verified webhook endpoint that dispatches a Laravel event.

Responsibility split, which drives every design decision:

- **BeeL does**: numbering from series, freezing totals at issue, the VERI*FACTU hash chain and registros (alta and anulación), asynchronous submission to the AEAT with retries on transient errors, QR data, PDF rendering, email delivery, NIF census checks, recurring invoices, Stripe auto-invoicing.
- **The app decides**: invoice type, recipient identification, every line amount and tax (type, rate, regime key, exemption, IRPF, recargo de equivalencia), corrective vs void, and what to do when the AEAT rejects a record. BeeL never recalculates amounts.
- **Nobody here does**: B2B e-invoicing under Crea y Crece (UBL, Facturae, Peppol, status reporting), FACe, TicketBAI or SII. See `references/spain-invoicing-scope.md`.

## When to activate

Any task that creates, issues, corrects, voids, lists, renders, emails or reports on invoices; manages customers, products, series, tax or VERI*FACTU configuration; onboards a company or managed account; validates a NIF; or handles BeeL webhooks. Also when designing invoicing data models, since the rules below constrain them.

## Workflow: issuing an invoice

1. Resolve a company scope: `app(BeelManager::class)->company()` (or pass `apiKey:`/`companyId:` for per-tenant credentials). The company id is a UUID, not the NIF.
2. On first use or onboarding, check `$company->issuingReadiness()`. It lists blockers such as a missing default series or an unsigned AEAT representation for production.
3. Make sure the customer exists (`$company->customers->create(...)`) or identify the recipient inline. Standard invoices need a NIF or an `alternative_id` for foreign recipients.
4. Build the request with explicit taxes on every line; see `references/beel-api.md` for tax rules and the foreign-customer table.
5. `create()` a draft (or pass `options.issue_directly`), with an `Idempotency-Key` and an `external_ref` from your own domain.
6. `issue()` it. Store the invoice id, number, and `verifactu.submission_status`.
7. Track the AEAT outcome from the invoice or the `verifactu.status.updated` webhook. Act only on `REJECTED`.
8. Store the PDF with `$company->invoices->storePdf($id, $path, disk: ...)`, fetch a 5-minute URL on demand with `getPdf()`, or `send()` it by email.
9. Fix mistakes with `createCorrective()`, not by editing; void only invoices that should never have existed.

```php
use Lenorix\BeelSdk\Http\RequestOptions;
use Lenorix\BeelSdk\Builder\InvoiceBuilder;
use Lenorix\BeelSdk\Generated\Model\CreateInvoiceRequestLinesItem;
use Lenorix\BeelSdk\Generated\Model\CreateInvoiceRequestLinesItemMainTax;
use Lenorix\LaravelBeel\BeelManager;

$company = app(BeelManager::class)->company();

$line = (new CreateInvoiceRequestLinesItem())
    ->setLineType('NORMAL')
    ->setDescription('Consulting services')
    ->setQuantity(1)
    ->setUnitPrice(100)
    ->setMainTax((new CreateInvoiceRequestLinesItemMainTax())->setType('IVA')->setPercentage(21)->setRegimeKey('01'))
    ->setIrpfRate(0); // explicit: an omitted IRPF rate inherits the company default

$request = InvoiceBuilder::create()
    ->forCustomer($customerId)             // a saved BeeL customer id
    ->externalRef('order-'.$order->id)     // at most one live invoice per external_ref
    ->addLineObject($line)
    ->build();

$draft = $company->invoices
    ->withOptions(new RequestOptions(idempotencyKey: 'invoice-create-order-'.$order->id))
    ->create($request);
$invoice = $company->invoices
    ->withOptions(new RequestOptions(idempotencyKey: 'invoice-issue-'.$draft->getId()))
    ->issue($draft->getId());

$invoice->getInvoiceNumber();
$invoice->getVerifactu()?->getSubmissionStatus(); // PENDING until the AEAT answers asynchronously
```

## Decision guide

- **Wrong data on an issued invoice** (amount, tax, recipient): corrective invoice. `PARTIAL` sends only the difference lines (negative quantities for reductions); `TOTAL` sends no lines and fully replaces the original. Codes: R1 legal error or LIVA art. 80.1, 80.2, 80.6 (returns, discounts), R2 insolvency (concurso), R3 bad debt (incobrable), R4 any other cause, R5 only for simplified invoices.
- **Invoice created by mistake that should never have existed**: `void()` with a reason of at least 10 characters. The number is not reused.
- **Retail ticket-like sale**: simplified invoice (`SIMPLIFIED`, AEAT F2) up to 3,000 EUR including VAT, anonymous recipient only up to 400 EUR, never with IRPF, recargo de equivalencia, reverse charge or cross-border operations.
- **Quote**: proforma (`PROFORMA`), non-fiscal and never sent to the AEAT; convert it with `convertToInvoice()`.
- **Foreign customer**: still a VERI*FACTU invoice with QR; identify the recipient with `alternative_id` and pick the exemption and regime key from the table in `references/beel-api.md`.

## Reference files

Read only what the task needs:

- `references/laravel-package.md`: this package's API, config, transport, errors, webhook endpoint and testing patterns.
- `references/beel-sdk.md`: every SDK resource method, builders, exceptions, idempotency and response unwrapping.
- `references/beel-api.md`: BeeL API behaviour, environments, errors, idempotency, accounts and companies, integrators (managed accounts, privileged scopes), invoice lifecycle, taxes, email, PDF and webhook events.
- `references/verifactu.md`: the VERI*FACTU regulation facts an app still has to respect (scope, deadlines, QR rules for app-rendered invoices, invoice types, corrections).
- `references/spain-invoicing-scope.md`: obligations outside BeeL and this package (B2B e-invoicing, Facturae, TicketBAI, SII) and their status.
