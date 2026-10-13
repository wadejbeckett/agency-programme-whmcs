# Changelog

## 0.2.0 — unreleased

- Settings tab on the addon page: mode card with a dry-run / live switch, a tier table per currency with add, remove, band preview and "copy at the WHMCS rate", product group, product, add-on and status pickers by name, the behaviour switches and invoice line text, an impact preview of every approved agency under the unsaved values, and a save that validates, reports problems inline and logs `settings_changed`. The saved keys are unchanged, so the raw Configure form (now the advanced route) and the CLI profile stay valid.
- Repository: product groups with active-service counts, products, add-on catalogue, currencies with rate and default flag, settings writes and the count of invoices checked.
- Fixed: names stored HTML-encoded by WHMCS (an add-on called "... CDN &amp; DDoS") were escaped a second time on the Configuration check and agency detail pages.

## 0.1.0 — unreleased

- Initial Agency Programme addon: approval flag on the client profile with an optional start date, qualifying spend and tier engine, discount line added to invoices on creation, dry-run mode, admin overview, agency detail and configuration check pages, change log, CLI rehearsal against a database snapshot, and an example profile. New orders of approved agencies are discounted too (setting `new_orders`, on by default): the pending items on the order invoice join the discount base and the tier is chosen on the counted spend plus their monthly equivalent.

### Known limitations / planned for 0.2

- Split the discount line into a taxed and an untaxed part when the discounted lines are mixed (today the whole line is taxed if any discounted line was).
- Recalculation writes the whole agency row, so it can overwrite an approval change made at the same moment; update only the computed columns.
- Filter the change log by event.
- An admin "apply to invoice" action for unpaid invoices that were skipped while dry-run was on.
- Show claim placeholder records (no item id) on the admin page.
- Branch `_upgrade` on the previous version once a release changes the schema.
