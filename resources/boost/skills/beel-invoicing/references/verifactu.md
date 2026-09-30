# VERI*FACTU: what an app on BeeL still has to know

Summarised from the AEAT VERI*FACTU FAQ (updated 2026-07-21) and Orden HAC/1177/2024 on 2026-09-26; QR placement and invoice types rechecked against BeeL's rules on 2026-09-30. BeeL implements the technical system (records, hash chain, submission). This file exists so the app does not contradict the rules in its own data model, UI or documents. For legal interpretation defer to the AEAT and the taxpayer's advisor.

## Legal basis

- Ley 11/2021 (Ley Antifraude), which added art. 29.2.j to the Ley General Tributaria.
- Real Decreto 1007/2023 (Reglamento de requisitos de los sistemas informáticos de facturación, RRSIF), amended by Real Decreto 254/2025.
- Orden HAC/1177/2024: technical and functional specifications.
- AEAT portal: https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu.html (FAQ in `/preguntas-frecuentes.html`, technical docs in `/informacion-tecnica.html`).

## Who and when

- Deadlines (Real Decreto-ley 15/2025, 2 Dec 2025): taxpayers of Impuesto sobre Sociedades before 1 Jan 2027; everyone else, including autónomos, before 1 Jul 2027. No further postponement was found as of 2026-09-26; re-check the AEAT note before hardcoding dates.
- Obliged: businesses and professionals established in Spain that invoice with software. Excluded: SII taxpayers (the regimes are mutually exclusive and SII taxpayers must not use VERI*FACTU or print its QR), taxpayers in the foral territories (País Vasco: TicketBAI; Navarra: its own system), anyone invoicing without software, non-residents without a permanent establishment in Spain, and holders of an exemption resolution.
- Scope is every invoice: exports, intra-EU operations, simplified invoices and invoices to foreign customers included (confirmed by DGT binding ruling V0100-26, 20 Jan 2026). A foreign customer never makes an invoice "non-VERI*FACTU".

## Modes

- VERI*FACTU mode (what BeeL uses when `verifactu.enabled`): every record is sent to the AEAT. Records do not need an electronic signature and no event log is required.
- NO VERI*FACTU mode: records stay in the system, each signed (XAdES Enveloped), with an event log, exportable on request. Not relevant when BeeL submits, but it explains why "you must sign every record" advice elsewhere does not apply here.
- A taxpayer that starts the year in VERI*FACTU mode stays in it until year end.

## Records and chaining (handled by BeeL)

- Two record types: alta (issue) and anulación (void). Records are never edited: a new record completes, corrects or voids the earlier one.
- Chaining: SHA-256 (64 uppercase hex) over the record's key fields plus the previous record's full hash. One chain per invoicing system and taxpayer, shared by alta and anulación records in generation order; it does not restart per series or per year. `RegistroAnterior` carries the full previous hash with issuer, number and issue date.
- Sending is automatic and continuous but batched: the system waits between submissions (initially 60 s, as the AEAT indicates in each response) or until 1,000 records are pending. If the AEAT is unreachable, invoicing continues and pending records are sent later in order, retrying at least hourly. Consequence for apps: the AEAT status of a just-issued invoice is `PENDING` for a while, so never block a user flow waiting for it.
- On rejection the AEAT returns an error code. Through BeeL, act on `submission_status = REJECTED`: the invoice is not registered, so void it and reissue it (a corrective is refused); the codes and what to do are in `beel-api.md` ("VERI*FACTU status on an invoice").

## QR code and legend

Required on every invoice issued from a covered system. BeeL's own PDF already includes them. Only relevant when the app renders its own invoice document (PDF, HTML, print):

- Use the QR content BeeL returns (`verifactu.qr_url`, or the image in `qr_base64`); never build or alter the URL yourself.
- ISO/IEC 18004 QR, error correction level M, printed between 30x30 and 40x40 mm.
- Placement: at the start of the invoice, before its content, once, on the first page (top centre or top left in portrait, top left in landscape).
- In VERI*FACTU mode, just below the QR (preferably centred): "VERI*FACTU" or "Factura verificable en la sede electrónica de la AEAT", in a type at least as large as the rest of the invoice data; only on invoices actually sent to the AEAT.
- Just above the QR: "QR tributario:". Leave at least 2 mm of blank margin on every side (6 mm recommended).
- Distribute the PDF only once the QR exists (`qr_url` is present from `PENDING`).
- For structured electronic invoices (XML) the QR image may be replaced by the URL it encodes.
- Third-party specifications describe the URL as the AEAT `ValidarQR` endpoint with `nif`, `numserie`, `fecha` (DD-MM-YYYY) and `importe` parameters; that is informative only, since BeeL supplies the URL.

## Invoice types and corrections

- F1 full invoice; F2 simplified invoice; F3 invoice replacing simplified ones (BeeL: `createSimplifiedExchange()`, which issues a STANDARD invoice recorded as F3 and voids the F2s; not an R5).
- Corrective (rectificativa) codes: R1 legal error or LIVA art. 80.1, 80.2, 80.6; R2 insolvency (art. 80.3); R3 bad debt (art. 80.4); R4 other causes; R5 correction of a simplified invoice. The AEAT distinguishes correction "por sustitución" (S, full corrected amounts) and "por diferencias" (I, only the difference); through BeeL choose `PARTIAL` or `TOTAL` as documented in `beel-api.md` and let BeeL build the record.
- Dates: fecha de expedición is the issue date; fecha de operación is when the sale or service happened (BeeL `operation_date`) and can differ.
- Anulación is for an invoice that should not exist; changes to a real operation (returns, discounts, wrong amounts) use a rectificativa. When in doubt, ask the taxpayer's advisor rather than choosing silently.

## Declaración responsable and responsibility

- The producer of the invoicing software self-certifies compliance in a declaración responsable (art. 13 RRSIF). There is no AEAT registration, approval or certification of software. It must be visible inside the software (e.g. a help or legal section) and available outside it (e.g. a PDF).
- Unverified: which declaración responsable covers an app built on BeeL (BeeL's, the app's, or both) depends on who is the SIF producer in that architecture. Do not assert it in code or UI; the app owner must confirm with BeeL and legal counsel.
- Sanctions (LGT art. 201 bis, as reported by secondary sources): up to 50,000 EUR per year for users of non-compliant systems; 150,000 EUR per year and system type for producers. Treat compliance-affecting shortcuts (editing issued invoices, deleting records, hiding the QR) as defects.

## App design rules derived from the above

- Model issued invoices as immutable; corrections are new documents linked to the original.
- Never delete issued invoices or their BeeL ids; keep the number, issue date, `invoice_hash`, `qr_url`, submission status and registration number with the invoice.
- Surface `REJECTED` submissions to a human with BeeL's `error_code`/`error_message`.
- Keep the QR and legend on every rendered copy of the invoice, including emails with PDFs and invoices for foreign customers.

## Sources

- BeeL rules QRC-001 to QRC-009 (https://docs.beel.es/verifactu/qr-and-pdf), checked on 2026-09-30.

- https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes.html and its sub-pages (cuestiones generales, firma, huella-hash, trazabilidad, sistemas VERI*FACTU, remisión al receptor, registros de alta y anulación, declaración responsable).
- https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/nota-informativa-ampliacion-plazo-adaptacion-facturacion.html
- Orden HAC/1177/2024: https://www.boe.es/diario_boe/txt.php?id=BOE-A-2024-22138
- DGT V0100-26 (foreign customers), as reported by https://www.econotax.es/verifactu-facturas-clientes-extranjeros/
