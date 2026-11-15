# Odoo addon: Weighbridge (`wb_weighbridge`)

For **Odoo 17** (Community or Enterprise). Adds the model `wb.ticket` and the menu *Weighbridge → Tickets*.

## Install
1. Copy this folder into an Odoo addons path; restart Odoo; Developer mode → Apps → *Update Apps List* → install **Weighbridge**.
2. Create an API key (Preferences → Account Security) for a user in the group *Weighbridge / Manager*.
3. In the weighbridge program: Setup → *Odoo & API* → enter URL, database, login, API key → *Test Odoo connection*.

## Modes
* **Push (default, recommended)**: the weighbridge writes tickets to Odoo (`create` / `write` on `wb.ticket`, keyed on `ticket_no`). Nothing to configure in Odoo.
* **Pull**: set System Parameters `wb.api_url` (e.g. `https://wb.example.com/api/v1`), `wb.api_token`, `wb.api_tz`; activate the scheduled action *Weighbridge: fetch new tickets*.

## Notes
* Partner / product / purchase order are linked automatically only when the typed name (or PO number) matches exactly one record; manual choices are kept.
* Not yet tested in a live Odoo (syntax-checked only): install on a test database first. Views use `<tree>` (Odoo 17); Odoo 18 needs `<list>`, and the cron `numbercall` field is not used in 18.
