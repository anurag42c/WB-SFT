# Changelog

## 1.3.0
- **Gate ⇄ weighbridge, both ways**: the ticket takes over party, material, challan, driver and (direction "Auto") the direction from the gate entry; the gate entry shows the factory ticket and its net weight.
- **Outside weighbridge slips**: enter a slip from another weighbridge at the gate (screen or `POST /api/v1/external_slips.php`, idempotent) - it becomes a completed ticket (source EXTERNAL) that flows into reports, invoice matching, Oracle/Odoo transfer and analytics; comparison with the factory weighing with tolerance and a red flag.
- **Analytics** screen + `GET /api/v1/analytics.php`: trucks and tonnes in/out by **product category** (new category on materials, also pulled from Odoo), balance, averages, per-day chart, top materials, gate register with time on site, slip differences, CSV.
- `GET/POST /api/v1/gate_entries.php`; tickets API carries `source`, `ext_weighbridge`, `ext_slip_no`. SQLite schema 7.
- Manual chapter 18 (appendices are now chapter 19), checklist items 30-33; about 70 new automated checks.

## 1.2.0
- **Odoo integration**: closed/cancelled tickets are pushed to Odoo (`wb.ticket`) over the standard JSON-RPC external API with store-and-forward and create-or-update by ticket number; optional pull of confirmed purchase orders (into the PO list used by the invoice checks) and of vendors/customers and products (into the drop-down lists). New setup tab *Odoo & API*, service `bin/sync_odoo.php` (systemd unit + NSSM lines).
- **Odoo addon** `odoo/wb_weighbridge` (Odoo 17): ticket list/form/pivot/search, partner/product/purchase-order linking, access groups, and a pull job (off by default) for when Odoo can reach the weighbridge.
- **REST API v1** extended: `tickets` (filters, by ticket_no, cursor), `documents` (list + full document), `purchase_orders` (read, write), `masters` (read, write), `ping`, public OpenAPI description (`openapi.php`); writes need an explicit switch; failed-token lockout; all-or-nothing validation.
- SQLite schema 6 (`odoo_*` columns on tickets); data stays in the local SQLite database.
- About 65 new automated checks (simulated Odoo JSON-RPC server, live API); manual chapter 17 and checklist items 27-29.

## 1.1.0
- **Invoice / material-return documents**: upload a picture or PDF, tag it PO invoice or material return, read it with Claude vision (structured JSON output) or offline Tesseract (rule-based reader), review beside the picture, verify.
- **Matching with the weighbridge**: automatic ticket linking (vehicle, challan no., supplier, material, weight, date), split loads, manual link/unlink, goods-vs-service line handling, quantity check with tolerance, unit suggestion.
- **Exceptions** with severities, resolve/waive with notes (kept across re-matching), a dashboard, and the reverse check *weighed but no invoice*.
- **PO checks** (CSV import or on-demand SAP lookup): PO present/known, vendor, items, rate, cumulative over-quantity; **return checks** against the original invoice.
- **Sending** verified documents to **SAP** (HTTP: basic/bearer, CSRF handshake, reference extraction, OData/JSON PO lookup) and/or **Oracle** (`WB_DOCUMENTS`, `WB_DOCUMENT_LINES`); hold policy, retry, and re-send when the data changes.
- Preflight check now reports PHP upload limits, GD/EXIF and Tesseract; health endpoint reports document counters.
- Manual: new chapter 13, Appendix B2 (Oracle tables), G (SAP data contract), H (exception catalogue); acceptance checklist items 21-26.

## 1.0.0
First release.
- Weighing: two-pass and single-pass (stored tare) tickets, stability rules, minimum weight, slips, reports, CSV.
- Indicators: RS-232, RS-485 (USB adapter), TCP gateways, Modbus RTU; ASCII continuous / poll-command parsing; simulator.
- Several scales per PC with a supervisor service; tickets may use different bridges for in and out.
- Setup page with live data and test buttons for scales, gates, camera/ANPR and Oracle.
- Oracle transfer: store-and-forward, idempotent MERGE, cancellations propagated.
- Plate recognition (Plate Recognizer, CodeProject.AI, local command) with vehicle/plate identity checks.
- Gates: boom-barrier control (HTTP / TCP / serial / Modbus), gate-pass register, blocked vehicles, exit rules, weighbridge boom automation.
- Security and operations: roles, audit log, login lockout, CSRF, encrypted secrets, backups, health endpoint, read-only REST API.
