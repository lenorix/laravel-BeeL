# Spanish invoicing obligations outside BeeL and this package

Checked on 2026-09-26. BeeL's documentation covers VERI*FACTU only; nothing below is provided by BeeL, `lenorix/beel-sdk` or `lenorix/laravel-beel`. Items marked "verify" come from secondary sources or are still pending regulation: confirm them before building on them, and never tell users an obligation is fulfilled because BeeL is integrated.

## Two separate regimes

| | VERI*FACTU | B2B electronic invoicing |
|---|---|---|
| Law | Ley 11/2021 (Antifraude), RD 1007/2023, Orden HAC/1177/2024 | Ley 18/2022 (Crea y Crece), RD 238/2026 |
| Controls | How invoicing software records invoices (integrity, traceability, AEAT submission) | The format and channel of invoices exchanged between Spanish businesses and professionals, and payment status reporting |
| Applies to | Every invoice of an obliged taxpayer: B2B, B2C, foreign customers | Invoices between businesses/professionals established in Spain |
| Handled by BeeL | Yes | No |

An invoice to a Spanish business will eventually need both: a VERI*FACTU record (BeeL) and a structured e-invoice sent through a regulated channel (another provider or module). Both must carry the same fiscal data.

## B2B electronic invoicing (Crea y Crece)

- Real Decreto 238/2026 (25 Mar 2026, BOE 31 Mar 2026, BOE-A-2026-7295) is in force since 20 Apr 2026, but its obligations only become enforceable after a Ministerial Order regulating the AEAT public e-invoicing solution, which was still pending: 12 months after that order for businesses whose volume of operations exceeded 8 million EUR in the previous year, 24 months for everyone else. Do not hardcode specific go-live dates; the dates circulating in articles are estimates. Verify at https://www.boe.es/buscar/act.php?id=BOE-A-2026-7295 and the AEAT news pages.
- Invoices must be issued and received in a structured format following the European semantic standard EN 16931.
- The recipient must report the invoice status (acceptance, rejection, full payment with its date) within 4 calendar days, to support the fight against late payments.
- Channels (verify): the AEAT public e-invoicing solution, or interoperable private platforms, which must also send a copy to the AEAT public solution. Plain email of a PDF will not be a valid B2B channel once the obligation applies.
- Formats (verify): UBL is reported as the reference syntax for the copy to the AEAT solution; Facturae and other EN 16931 syntaxes are reported as accepted. A hybrid PDF/A-3 carrying the XML is reported as a convenient delivery format, with the XML as the legally relevant document.
- Not affected (verify): invoices to consumers (B2C) and to customers established outside Spain, which keep using traditional PDFs with the VERI*FACTU QR.

Design guidance: keep the app's invoice domain model format-agnostic (issuer, recipient, lines, taxes, totals, status history) so a B2B e-invoicing adapter can be added next to BeeL without changing how invoices are issued. Model the commercial status (accepted, rejected, paid with date) now; it is useful anyway and will be required.

## Public sector (B2G)

Invoices to Spanish public administrations are submitted through FACe (https://face.gob.es) in Facturae format (https://www.facturae.gob.es). Not covered by BeeL; verify requirements at those sites.

## TicketBAI and foral territories

Taxpayers domiciled for tax purposes in the País Vasco (Álava, Bizkaia, Gipuzkoa) use TicketBAI, and Navarra has its own system; they are outside VERI*FACTU. BeeL's docs do not mention them. An app serving those taxpayers needs a different integration; do not route them through BeeL's VERI*FACTU flow without confirming with BeeL.

## SII

Taxpayers under the Suministro Inmediato de Información (SII) are excluded from VERI*FACTU and must not print its QR. They report invoices through SII, which BeeL does not document. Check the taxpayer's regime during onboarding.

## Other countries

Other countries' tax and e-invoicing regimes are out of scope of BeeL and this package. An app selling abroad from Spain still issues VERI*FACTU invoices for its Spanish taxpayer; the customer's local rules are a separate concern.

## Sources

- https://www.boe.es/buscar/act.php?id=BOE-A-2026-7295 (RD 238/2026)
- https://www.cuatrecasas.com/es/spain/fiscalidad/art/reglamento-facturacion-electronica-obligatorio-operaciones
- https://www.iberley.es/noticias/publicado-boe-rd-factura-electronica-obligatoria-b2b-36274
- https://sede.agenciatributaria.gob.es/Sede/iva/sistemas-informaticos-facturacion-verifactu/preguntas-frecuentes.html (VERI*FACTU scope and exclusions)
