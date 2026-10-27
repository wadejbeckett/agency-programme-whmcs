# Changelog

## 0.2.0 — unreleased

Dashboards for the programme, on every WHMCS surface an administrator or an agency sees. No schema change: `agencydiscount_upgrade` logs the upgrade and leaves the tables as they are.

- Settings tab on the addon page: mode card with a dry-run / live switch, a tier table per currency with add, remove, band preview and "copy at the WHMCS rate", product group, product, add-on and status pickers by name, the behaviour switches and invoice line text, an impact preview of every approved agency under the unsaved values, and a save that validates, reports problems inline and logs `settings_changed`. The saved keys are unchanged, so the raw Configure form (now the advanced route) and the CLI profile stay valid.
- Overview tab redesigned: tiles for approved agencies (at a tier, waiting for a start date), qualifying spend per month and the discount expected in the next 35 days per currency, and the mode; the agencies table with a tier badge, a progress bar and the amount to the next tier, the next invoice and its discount, flags for a start date and for a currency without tiers, filters (all, at a tier, not yet started, no tiers for currency) and a client-name search; a side column with what needs attention (dry-run age, currencies without tiers, agencies within 5% of the next threshold) and the six most recent change-log entries as sentences. The change log tab shows the same sentence as a Summary column.
- Admin home widget (`AdminHomeWidgets`), built the way WHMCS builds its own widgets (`modules/widgets/`): an `icon-stats` block of four figures (spend per month, discount in the next 35 days, agencies at a tier, mode) that both admin themes style like the Support widget's, the agency list inside the theme's `widget-content-padded` scroll box with two short lines per agency (name and spend; tier badge and next discount or start date), and a footer with the last cron check and a link to the addon. No theme rule is overridden; the module's own styles cover only the footer and the two-line rows. The widget id is `AgencyProgramme` (the dashboard's refresh, minimise and hide buttons need an id without a namespace). Requires the "View Income Totals" permission and a role on the addon's access list; a failure renders one line and goes to the activity log.
- Client summary card (`AdminAreaClientSummaryPage`) for approved agencies: status badge, spend, next invoice saving, distance to the next tier (a slim progress bar), dry-run note and links to the counted items, the change log and the profile tab. Shown only to admin roles on the addon's access list.
- Profile tab: "Approved by <admin> on <date>" under the checkbox, the start-date explanation, and a live "Right now" box (spend, tier, next invoice, progress to the next tier) beside the fields. The field names are unchanged.
- Client area, read-only and visible to approved agencies only: a home panel (`ClientAreaHomepagePanels`) with tier, spend, next-invoice discount, saved this year and the amount to the next tier, and a module page (`index.php?m=agencydiscount`) with the tier table marked "You are here", what counts and what does not, the counted services and add-ons with monthly equivalents and totals, and the discount saved this calendar year. Styled by the client theme (Bootstrap 4 classes).
- `tools/preview/`: an offline render harness that wraps the home widget (next to stand-ins built from the Billing and Support widgets' markup), the overview and settings tabs and the client summary card in a WHMCS admin theme's frame (theme files referenced by path, not shipped) for headless-browser screenshots.
- Repository: product groups with active-service counts, products, add-on catalogue, currencies with rate and default flag, settings writes, the count of invoices checked, an admin's role id and the applied discount total of a client from a date.
- The example profile is now the generic `profiles/example.json` (placeholder ids, USD tiers) instead of one installation's settings, and `tests/ProfilesTest.php` checks every file under the module (including `lib/ClientArea`, `templates` and `lang`) with generic host-name and currency patterns, and the profiles for installation-specific values.
- Fixed: names stored HTML-encoded by WHMCS (an add-on called "... CDN &amp; DDoS") were escaped a second time on the Configuration check and agency detail pages.
- Fixed: a counted service or add-on with a negative recurring amount made the agency's recalculation throw, so its invoices were not discounted and the dashboards failed. The negative amount now keeps its sign in the monthly equivalent and is left out of the upcoming-invoice estimate, as it already was on the invoice.

## 0.1.0 — unreleased

- Initial Agency Programme addon: approval flag on the client profile with an optional start date, qualifying spend and tier engine, discount line added to invoices on creation, dry-run mode, admin overview, agency detail and configuration check pages, change log, CLI rehearsal against a database snapshot, and an example profile. New orders of approved agencies are discounted too (setting `new_orders`, on by default): the pending items on the order invoice join the discount base and the tier is chosen on the counted spend plus their monthly equivalent.

### Known limitations / planned for 0.2

- Split the discount line into a taxed and an untaxed part when the discounted lines are mixed (today the whole line is taxed if any discounted line was).
- Recalculation writes the whole agency row, so it can overwrite an approval change made at the same moment; update only the computed columns.
- Filter the change log by event.
- An admin "apply to invoice" action for unpaid invoices that were skipped while dry-run was on.
- Show claim placeholder records (no item id) on the admin page.
- Branch `_upgrade` on the previous version once a release changes the schema.
