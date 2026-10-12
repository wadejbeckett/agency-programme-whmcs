# Changelog

## 0.1.0 — unreleased

- Initial Agency Programme addon: approval flag on the client profile with an optional start date, qualifying spend and tier engine, discount line added to invoices on creation, dry-run mode, admin overview, agency detail and configuration check pages, change log, CLI rehearsal against a database snapshot, and an example profile. New orders of approved agencies are discounted too (setting `new_orders`, on by default): the pending items on the order invoice join the discount base and the tier is chosen on the counted spend plus their monthly equivalent.

### Known limitations / planned for 0.2

- Split the discount line into a taxed and an untaxed part when the discounted lines are mixed (today the whole line is taxed if any discounted line was).
- Recalculation writes the whole agency row, so it can overwrite an approval change made at the same moment; update only the computed columns.
- Filter the change log by event.
- An admin "apply to invoice" action for unpaid invoices that were skipped while dry-run was on.
- Show claim placeholder records (no item id) on the admin page.
- Branch `_upgrade` on the previous version once a release changes the schema.
