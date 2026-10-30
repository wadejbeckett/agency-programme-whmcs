# Agency Programme for WHMCS

Agency Programme is a WHMCS addon that runs a programme for approved agency clients. An administrator marks a client as an approved agency on the client profile, the module measures the client's qualifying monthly spend and places the client in a tier, and each tier carries a benefit. Today that benefit is a percentage discount on the client's qualifying hosting services and add-ons, applied automatically as a named negative line on each new invoice. No service price is ever changed. The module has a dry-run mode, an admin overview with a home widget and a card on each agency's client summary, a read-only client-area page for agencies, a full change log and a command-line rehearsal, so the numbers can be checked before a single invoice is touched.

Licensed under the AGPL-3.0-or-later. Project home: https://github.com/wadejbeckett/agency-programme-whmcs

## What it replaces and where it is going

The module replaces hand-set promotion codes and client-group discounts for agency clients: the approval lives on the client profile, the tier follows the client's actual monthly spend, and the discount is visible as its own invoice line rather than hidden in a service price. The tier engine and the approval flag are the base for what comes next: agency applications from the client area, reporting on the programme, and an agency dashboard.

## How it works

- **Approval.** A client only qualifies once an administrator ticks "Approved agency" on the client's Profile tab in the WHMCS admin area. Unapproved clients are never discounted.
- **Qualifying spend.** The module adds up the recurring amount of every counted service and add-on, each converted to a monthly equivalent (a 12-month annual price counts as one twelfth). Which items count is configuration: product groups, individual products, add-on ids and statuses.
- **Tiers.** Tiers are defined per currency. A client gets the highest tier whose threshold the monthly spend is strictly above: spend of exactly 5,000 against a threshold of 5,000 does not qualify. A client in a currency with no tiers gets no discount.
- **Start date.** Each agency has an optional "Discount starts on" date on the client profile. It is compared with each invoice's due date (an invoice without a due date is compared with the day it is created): an invoice due before the start date gets no discount line and the module logs `not_yet_effective`; an invoice due on or after it is discounted, even when WHMCS generates it in advance, before the start date. The spend and tier are calculated and shown in the admin pages either way. Leave it empty for the discount to apply immediately.
- **The invoice line.** When WHMCS creates an invoice (the `InvoiceCreation` hook), the module recalculates the client's spend and tier, totals the discountable lines, and adds one negative line of type `AgencyDiscount` named by the "Invoice line text" setting, carrying the invoice's payment method (two lines when the discounted lines mix taxed and untaxed items, see [Tax](#tax)). WHMCS recalculates the invoice totals from its lines afterwards; see [What has and has not been verified](#what-has-and-has-not-been-verified). The module records each invoice it discounts or dry-runs, so an invoice is never discounted twice.
- **What is discounted.** The Hosting and Addon lines of counted services and add-ons. Not discounted: a service's Hosting line when the same invoice also carries that service's promotion discount (a `PromoHosting` line), unless "Stack with promotions" is on. Such services still count toward spend, and once a promotion no longer reduces the service's invoices, the service is discounted again. Upgrades, domains, setup fees and manual items are never discounted.
- **New orders.** WHMCS invoices a new order while the ordered service and add-ons are still Pending, so they are not yet in the counted spend. With "Discount new orders" on (the default), the Hosting and Addon lines of the client's pending qualifying items on that invoice are discounted as well, and the tier for that invoice is chosen on the counted monthly spend plus the monthly equivalent of those pending items, so a first large order can earn a tier on its own. The promotion rule above applies to pending lines too. The spend and tier stored for the agency and shown in the admin pages stay on counted items; the order's items join them once they are Active. With the setting off, a new order's invoice gets no discount and the discount starts with the first renewal. A status in "Counted statuses" always takes the ordinary route, so adding Pending there counts pending items toward spend everywhere and never counts a line twice.
- **Annual and longer cycles.** An annual service is invoiced once a year and receives whatever tier the client holds on the day that invoice is created.
- **Recalculation.** Spend and tier are also refreshed before the cron invoicing run and by the daily cron, and on demand from the admin overview.
- **What is left alone.** Nothing in `tblhosting` or `tblhostingaddons` is modified. The client area keeps showing the undiscounted recurring prices; the discount appears only on invoices.

### Tax

WHMCS taxes each invoice line on its own "taxed" flag, so the discount line carries the flag of the lines it discounts. Normally an invoice gets one discount line. An invoice whose discounted lines mix taxed and untaxed items gets one discount line per tax treatment: a taxed line worth the percentage of the taxed lines, and an untaxed line worth the percentage of the untaxed lines, each with its own count in the line text. A single taxed line for the whole discount would lower the tax by the discount on the untaxed items too. Both lines are recorded and appear as one `applied` entry in the change log. Each line is rounded on its own, so the two can differ by 0.01 from the percentage of the combined amount.

### Rounding and money

All amounts are handled as decimal strings with `bcmath`, never as floats. Monthly equivalents and discounts are rounded half-up to two decimal places; each item's monthly equivalent is rounded before the items are summed. Tier thresholds are strictly-above comparisons on the two-decimal monthly total. A billing cycle the module does not recognise (for example one-time or free items) has a monthly equivalent of 0.00. A negative recurring amount keeps its sign (rounded like its positive counterpart) and lowers the spend; its invoice line is never discounted.

## Requirements

- WHMCS 9.x (8.x on PHP 8.1 or newer is untested). Tested on WHMCS 9.1.0 (PHP 8.3).
- PHP 8.1 or newer, with the `bcmath` and `json` extensions

## Install

1. Copy `modules/addons/agencydiscount` into the WHMCS installation's `modules/addons/` directory.
2. In the admin area, activate it under System Settings > Addon Modules. Activation creates three tables (`mod_agencydiscount_clients`, `mod_agencydiscount_log`, `mod_agencydiscount_invoices`) and starts in dry-run mode.
3. Grant the admin role groups that should see the module access to it (Configure on the same page). The same access list decides which roles see the admin home widget, the card on the client summary page and the "Agency discount" field on the client's Profile tab, and so which roles can approve an agency.
4. Open Addons > Agency Programme > Settings and set the tiers, the qualifying groups, products and add-ons, and the statuses (see below). `profiles/example.json` shows one complete configuration.
5. Approve agencies: open a client, go to the Profile tab and tick "Approved agency" under "Agency discount". Add a note and a start date if wanted.
6. Check the Overview and Configuration check pages while dry-run is on. The overview shows each agency's spend, tier, next invoice and discount.
7. When the numbers are right, press "Switch to live" on the Settings tab.

Upgrading: replace the whole `modules/addons/agencydiscount` directory with the new one (move the old directory aside, then put the new one in place; do not copy the new folder over the old one, which leaves files that later versions removed). WHMCS then calls `agencydiscount_upgrade`, which records each change in the activity log. From 0.2.1 to 0.2.2 nothing changes in the database. From 0.1.0 or 0.2.0 to 0.2.1 it changes `mod_agencydiscount_invoices` so an invoice can hold one record per discount line (columns `id`, `line` and `taxed`; existing records keep their data and take the taxed flag of their invoice line), and replaces the invoice line text with the new default when the old default (`Agency discount {percent}% on {count} qualifying items`) is still stored unchanged. A customised text is left as it is. Back up `mod_agencydiscount_invoices` before upgrading.

## Configuration

Addons > Agency Programme > **Settings** is where the module is configured. It saves into the same addon settings the engine reads (the keys below), so the raw form under System Settings > Addon Modules > Agency Programme > Configure and the CLI profile stay valid; that raw form is the advanced route and its fields say so.

The Settings tab has:

- **Mode card.** Whether dry-run is on and what that means, the last recalculation, how many invoices the module has checked since activation, and a "Switch to live" / "Switch to dry-run" button.
- **Discount tiers.** One editable table per WHMCS currency: the monthly spend each tier is above (0.00 allowed) and its percentage, "+ Add tier", "Remove", a band preview with a worked example per band, and "Copy <default> tiers to <code> at the WHMCS rate", which fills a currency's rows from the default currency's at WHMCS's exchange rate, rounded to two places, without saving.
- **What counts.** Product groups as checkboxes with the number of active services in each (hidden groups last), products by group and add-ons as filterable checkbox lists. An id the configuration names but WHMCS no longer has stays ticked and flagged so it can be removed.
- **Counted statuses.** Checkboxes; Pending is normally left to "Discount new orders".
- **Behaviour.** The four switches with one-line explanations and the invoice line text with a rendered example.
- **Preview impact** validates the form and shows each approved agency's qualifying spend, tier and next-invoice discount under the saved settings and under the unsaved values, without saving. **Save settings** validates, reports problems inline (nothing is saved while there are any) and logs `settings_changed` with the keys that changed, in the module change log and the WHMCS activity log. Every POST carries the WHMCS admin token.

Defaults are those in `Config::defaults()`. Yes/no settings are stored as `on` when ticked. The module reads `on`, `yes`, `1` and `true` as yes and empty, `off`, `0` and `no` as no; any other value is read as the safe choice: dry-run and activity log on; stacking, new-order discounts and deleting data off.

| Setting | Key | Default | Meaning |
| --- | --- | --- | --- |
| Tiers (JSON) | `tiers` | `{}` | Discount tiers per currency, see below. Must define at least one currency before any discount can apply. |
| Qualifying product group IDs | `product_groups` | empty | Comma-separated. Services in these groups count toward spend and receive the discount. |
| Qualifying product IDs | `products` | empty | Comma-separated. Adds individual products outside those groups. |
| Qualifying add-on IDs | `addons` | empty | Comma-separated product add-on ids. |
| Counted statuses | `statuses` | `Active` | Comma-separated service and add-on statuses that count. At least one is required. |
| Dry-run mode | `dry_run` | on | Calculate and log, but do not change any invoice. An unrecognised value counts as on. |
| Stack with promotions | `stack_with_promotions` | off | Also discount a service's invoice line when the same invoice carries that service's promotion discount. |
| Discount new orders | `new_orders` | on | Also discount an approved agency's new-order invoice, whose items are still Pending. The tier for that invoice is chosen on the counted spend plus the ordered items' monthly equivalent. |
| Invoice line text | `description` | `Agency discount {percent}% on {count} qualifying {items}` | Text of the discount line. Placeholders: `{percent}`, `{count}`, and `{items}`, which reads "item" for a count of 1 and "items" otherwise. Must not be empty. |
| Write to activity log | `activity_log` | on | Also record applied discounts and tier changes in the WHMCS activity log. Settings and mode changes are always recorded there. |
| Change log retention (months) | `log_retention_months` | 24 | The daily cron removes change log entries older than this. 0 keeps the log forever, and so does any value that is not a whole number from 0 to 600. Approvals and invoice records are never removed by it. Set under System Settings > Addon Modules > Agency Programme > Configure. |
| Delete module data on deactivate | `delete_data` | off | The tables with the agency approvals, the change log and the invoice records are removed on deactivate only when this is on. Off, a missing setting or an unrecognised value keeps them. |

An invalid configuration (bad JSON, a non-numeric id, no statuses) is reported at the top of the addon page and, once per request, in the WHMCS activity log. While it is invalid, no discount is applied and invoicing carries on as normal.

### Tiers format

`tiers` is a JSON object keyed by three-letter uppercase currency code. Each currency lists tiers in ascending order of `above`, the monthly spend (in that currency) the client must exceed, and `percent`, the discount from above 0 up to 100. A first tier with `"above": 0` gives every client with any qualifying spend that discount.

```json
{"USD":[{"above":500,"percent":5},{"above":1000,"percent":10}]}
```

With this setting a client in USD with qualifying monthly spend of 500.00 gets no discount, 500.01 gets 5% and above 1000.00 gets 10%. Add one key per currency in use; a currency without an entry gets no discount, and the Configuration check page flags it.

## Admin page

Addons > Agency Programme has four tabs (Overview, Settings, Change log, Configuration check) plus a Detail link per agency.

- **Overview.** Four tiles: approved agencies (how many sit at a tier and how many wait for a start date), qualifying spend per month and the discount expected in the next 35 days, each summed per currency, and the mode. Below them one row per approved agency in five columns: client; qualifying monthly spend (the time it was last recalculated shows on hover); a tier badge with, under it, whether the discount is active or starts on a date and a link to the detail; a progress bar through the current tier band with the amount still needed for the next tier; and the next invoice's date with its discount under it. A line under the table gives when the figures were recalculated. Rows waiting for a start date are highlighted in yellow and rows in a currency without tiers in red. The table can be filtered (all, at a tier, not yet started, no tiers for currency) and searched by client name; both are plain links and a GET form, so they work without scripts. A side column lists what needs attention (how long dry-run has been on, currencies with approved agencies but no tiers, agencies within 5% of the next threshold, each linking to where it is fixed) and the six most recent change-log entries as sentences. "Recalculate now" refreshes every agency. Viewing the page changes nothing.
- **Agency detail.** The counted services and add-ons with their cycle, amount, monthly equivalent, next invoice date and promotion flag, the upcoming invoices in the next 35 days with the base and the discount each would receive, and a link to that client's change log. The upcoming invoices are an estimate from the next invoice dates: they include services on a promotion, because whether a promotion still reduces an invoice is only known from that invoice's lines, and dates before the agency's start date show no discount. Pending new-order items are not listed and not estimated; they are only discounted on the order invoice itself.
- **Settings.** The configuration, described under [Configuration](#configuration).
- **Change log.** Every event, newest first, filterable by client, with its actor, a one-sentence summary and the raw detail.
- **Configuration check.** Shows that each configured group, product and add-on id exists, which currencies WHMCS has and whether tiers are configured for each, and the tier table.

Change log events:

| Event | Meaning |
| --- | --- |
| `approved` | An administrator approved a client as an agency. |
| `revoked` | An administrator revoked the approval. |
| `note_changed` | The agency note was edited. |
| `effective_from_changed` | The "Discount starts on" date was set, changed or cleared. |
| `effective_from_invalid` | A start date that is not a valid `YYYY-MM-DD` date was submitted and ignored. |
| `tier_changed` | A recalculation moved the agency to a different tier (or to none). |
| `no_tier` | The agency has no tier: no tiers are configured for its currency, or at invoice time its spend is not above the first threshold. |
| `applied` | A discount line was added to an invoice (two lines, listed in the detail, when the invoice mixes taxed and untaxed items). |
| `dry_run` | A discount line would have been added; dry-run mode (or a rehearsal) prevented it. |
| `already_processed` | The invoice has already been handled; nothing was changed. |
| `zero_amount` | The discount rounded to nothing. |
| `no_qualifying_lines` | The invoice had no discountable lines. |
| `not_yet_effective` | The invoice is due before the agency's start date. |
| `invoice_not_found` | The hook was called for an invoice that does not exist. |
| `client_missing` | An approved agency's client no longer exists in WHMCS. |
| `recalculate_failed` | Recalculating one agency failed with an unexpected error (the message is in the detail); the other agencies were still recalculated. |
| `settings_changed` | An administrator saved the Settings tab; the detail lists each changed key with its old and new value. |
| `mode_changed` | An administrator switched between dry-run and live on the Settings tab. |

## Admin home widget

The `AdminHomeWidgets` hook adds an "Agency Programme" widget to the admin dashboard: the number of approved agencies, qualifying spend per month and the discount expected in the next 35 days (per currency), the mode badge, one row per agency with its tier badge and next invoice, the last cron check (the newest recalculation) and a link to the addon. It is read-only and never cached. Two things gate it: the widget requires the WHMCS admin permission "View Income Totals" (the one WHMCS's own billing widget uses), and it only answers for an admin whose role is on the addon's access list, the roles ticked under System Settings > Addon Modules > Agency Programme > Configure; any other admin sees "Agency Programme is not available to your admin role". If the figures cannot be built, the widget shows "Agency Programme: unavailable (see activity log)" and the error is in the WHMCS activity log; the dashboard itself is never affected. The widget is only registered when WHMCS's widget base class exists, so the unit tests and the CLI never load it.

## Client profile

Two places on an agency's client record show the programme:

- **Summary tab card** (`AdminAreaClientSummaryPage` hook). For an approved agency only: a tier badge (or "No tier", or the warning that its currency has no tiers), a start-date note while the discount has not started, the qualifying monthly spend, the next invoice and its saving, a progress bar with the amount to the next tier, a note while dry-run is on, and links to the counted items (the agency detail), the change log for the client and the approval on the profile tab. It is shown only to admin roles on the addon's access list; any other client, role or failure gives nothing.
- **Profile tab section** (`AdminClientProfileTabFields` and `AdminClientProfileTabFieldsSave` hooks). The "Agency discount" field: the "Approved agency" checkbox with who approved the client and when, the note, the "Discount starts on" date with an explanation, the stored summary line and, beside them for an approved agency, a live "Right now" box with the same figures as the summary card. Because an approval grants a recurring discount, the field is shown only to admin roles on the addon's access list; a save from any other role is ignored (nothing is stored) and noted in the WHMCS activity log. The form field names (`agencydiscount_present`, `agencydiscount_approved`, `agencydiscount_note`, `agencydiscount_effective_from`) are unchanged from 0.1.0.

## Client area

An approved agency sees its programme in the client area; everything there is read-only, and clients outside the programme see nothing but a one-line notice on the module page. The client is identified from the WHMCS session.

- **Home panel** (`ClientAreaHomepagePanels` hook). "Your agency programme": tier, qualifying monthly spend, the discount on the next invoice, the amount saved this year, the amount to the next tier, and a button to the module page. Not added for other clients, and never on a failure (which goes to the activity log).
- **Module page** (`index.php?m=agencydiscount`, `agencydiscount_clientarea`, template `templates/clientarea.tpl`, login required). The tier table for the client's currency with its tier marked "You are here", what counts (the configured product groups, products and add-ons by name) and what does not, the client's counted services and add-ons with billing cycle, amount and monthly equivalent, the totals, and "Saved in <year>": the discount lines the module added (applied, not dry-run) to the client's invoices created this calendar year, from `mod_agencydiscount_invoices`. Notes appear while dry-run is on or a start date is ahead.

The template uses the client theme's Bootstrap 4 classes (WHMCS's "twenty-one" theme) and no styling of its own beyond progress-bar widths; the client theme decides how it looks. The page's variables are built by `ClientArea\PageData` and the template escapes every value. The client area still shows undiscounted recurring prices on services; the discount appears on invoices.

## Hooks

| Hook | What the module does |
| --- | --- |
| `InvoiceCreation` | Adds the discount line to a new invoice of an approved agency. |
| `PreInvoicingGenerateInvoiceItems` | Recalculates every agency before the cron invoicing run. |
| `DailyCronJob` | Recalculates every agency daily. |
| `AdminClientProfileTabFields` | Renders the "Agency discount" profile field with the "Right now" box. |
| `AdminClientProfileTabFieldsSave` | Saves the approval, note and start date (admin roles on the addon's access list only). |
| `AdminAreaClientSummaryPage` | Renders the summary card for an approved agency. |
| `AdminHomeWidgets` | Returns the admin home widget (only when WHMCS's widget base class exists). |
| `ClientAreaHomepagePanels` | Adds the agency's panel to the client home page. |

None of the hooks throws: a failure is written to the WHMCS activity log and the hook returns, so invoicing, the admin pages and the client area keep working.

## Rehearsal CLI

The command-line tool replays the module against a copy of the data without writing anything, so tiers and qualifying items can be tuned before activation.

1. Export a snapshot from a copy of the WHMCS database (read-only `SELECT`s, needs the `mariadb` client):

   ```bash
   tools/snapshot.sh OUTDIR DATABASE [mariadb client options...]
   ```

   A snapshot contains client data. Keep it outside the repository and never commit it.

2. Rehearse with a settings file such as `profiles/example.json`:

   ```bash
   bin/agency-discount rehearse --snapshot DIR --settings FILE [--approve ID,ID] [--days N] [--invoice N] [--today YYYY-MM-DD]
   ```

   `--approve` lists the client ids to treat as approved agencies, `--days` how far ahead to list upcoming invoices (default 35), `--invoice` also runs the discount on one invoice (always as a dry run) and `--today` sets the day. `bin/agency-discount help` prints the usage. The rehearsal does not model the per-agency start date.

### Real-database sandbox

For a stronger check than the in-memory rehearsal, `tools/sandbox.sh` copies the WHMCS tables the module touches into a throwaway database and the integration tests run the real module entry points against it (activate, profile save, `InvoiceCreation`, daily recalculation, deactivate):

```bash
tools/sandbox.sh SOURCE_DB SANDBOX_DB [mariadb client options...]
```

`SANDBOX_DB` is dropped and recreated; it must differ from `SOURCE_DB` and contain the word `sandbox`. The source is only read. The integration suite is opt-in and skipped unless its variables are set:

| Variable | Used by | Meaning |
| --- | --- | --- |
| `AGENCYDISCOUNT_SANDBOX_DSN` | sandbox test | PDO DSN of the sandbox, for example `mysql:host=127.0.0.1;dbname=whmcs_sandbox`. The database name must contain `sandbox`. Needs PHP's `pdo_mysql`. |
| `AGENCYDISCOUNT_SANDBOX_USER` | sandbox test | Database user (default `root`). |
| `AGENCYDISCOUNT_SANDBOX_PASS` | sandbox test | Database password (default empty). |
| `AGENCYDISCOUNT_MARIADB_ARGS` | SQL check | `mariadb` client options, for example `--defaults-file=/path/to/my.cnf -u root`. |
| `AGENCYDISCOUNT_MARIADB_DB` | SQL check | Name of a WHMCS database copy to check every SQL statement against (read-only, plus a scratch schema round trip). |

```bash
vendor/bin/phpunit --testsuite integration
```

### What has and has not been verified

The hooks were exercised against a MariaDB copy of a WHMCS 9.0.8 database through Illuminate's Capsule. On licensed WHMCS installations (9.0.9 from 12 October 2026, 9.1.0 from 25 October 2026), this has been verified:

- WHMCS recalculates invoice totals after the `InvoiceCreation` hook returns. On each of five invoices from the automatic invoice run of 25 October 2026, the subtotal and total equalled the sum of the lines, including the discount line.
- The `InvoiceCreation` hook runs on invoices from the automatic invoice run, and an invoice without qualifying lines is skipped and logged.
- The addon settings saved through WHMCS's Configure form, notably the tiers JSON, are decoded correctly: a changed tier table gave the expected tiers on recalculation.
- `generate_token()` output: the overview's "Recalculate now" form and the Settings tab's mode switch were accepted.
- Approving an agency on the client's Profile tab (a note without a start date) stores the approval.
- The admin pages (Overview, Settings, Change log, Configuration check, agency detail, client summary card) render inside the WHMCS admin template, and the admin home widget renders on the real dashboard in the Iris admin theme (in Blend it has been seen in offline renders only); the widget and the profile field honour the admin role checks.
- `agencydiscount_upgrade()` runs on the first admin page load after new files are copied: 0.1.0 to 0.2.0, and 0.2.0 to 0.2.1 with its change to `mod_agencydiscount_invoices`, each recorded in the activity log, with existing records intact. It runs when System Settings > Addon Modules is opened, and a Configure form opened before the new files were in place saves the old values back if it is submitted afterwards: reopen the page before saving.

These points remain to be confirmed on a real install:

- How WHMCS's input sanitiser encodes a profile note containing quotes or ampersands, and the start date field; both are decoded before storing.
- That the Settings tab's inline script (currency sub-tabs, add and remove tier rows, name filters) runs unchanged inside the WHMCS admin template; the server accepts any number of rows, so the page works without it.
- How the yes/no `Default` values render in the addon settings screen.
- That `agencydiscount_deactivate()` runs before WHMCS deletes the module's settings rows; this decides whether "Delete module data on deactivate" is honoured. If WHMCS deletes the settings first, the module finds no setting and keeps its tables.
- That the discount line, which copies the invoice's `paymentmethod`, displays correctly in WHMCS invoice views and the invoice PDF.
- The client-area home panel's `addChild` attributes (`label`, `icon`, `extras` with `btn-link` and `btn-text`, `bodyHtml`) against WHMCS's `\WHMCS\View\Menu\Item`, which is stubbed here, and that the module page's template passes WHMCS's Smarty security policy (it uses only `escape`, `if` and `foreach`). The template was rendered with Smarty 3.1 outside WHMCS.
- That `\WHMCS\Session::get('uid')` gives the logged-in client as the client area expects.
- How a new-order invoice carries a setup fee (inside the Hosting line or as a separate line), and whether WHMCS applies available credit before the discount line is added; no live invoice for an approved agency has had either.
- The admin home widget on a real dashboard in the Blend admin theme; it has only been rendered offline.
- Tax: every invoice observed so far carried no tax, so neither the taxed discount line nor the split into a taxed and an untaxed line has been seen on a real invoice.

This is why the module ships with dry-run on. Run it in dry-run on the real installation first, then check the first live invoice.

## Uninstall

Deactivating the module under System Settings > Addon Modules keeps its three tables, so the agency approvals, the change log and the invoice records are still there when it is activated again. To remove them, tick "Delete module data on deactivate" on the Settings tab (or in the module's Configure form) first; deactivating then drops the tables. Back up `mod_agencydiscount_clients`, `mod_agencydiscount_log` and `mod_agencydiscount_invoices` first (for example with `mysqldump`). Discount lines on invoices already issued stay as they are. No service price was ever altered, so there is nothing to restore.

## Limitations

- Invoices are discounted when they are created. An invoice edited by hand, or created before the module was active, is not recomputed.
- An approved agency's new orders are discounted only while "Discount new orders" is on (the default). With it off, WHMCS invoices the order while the service is still Pending, so the order invoice carries no discount and the discount starts with the first renewal.
- Invoices built by hand in the admin area start empty when WHMCS creates them, so the hook sees no qualifying lines and they are never discounted.
- An invoice created while dry-run is on is recorded as processed and is not discounted retroactively when dry-run is switched off.
- Suspended or otherwise non-active services do not count unless their status is added to "Counted statuses".
- A currency without tiers gets no discount.
- There is no client-facing application flow: an administrator approves agencies.
- The client area shows undiscounted recurring prices on services; the discount appears on the invoice and the programme page.
- Tax-inclusive pricing, compound (second-level) tax and EU-style tax setups are untested; the tax handling has only been checked in the unit tests with tax-exclusive line flags.

## Development

```bash
composer install
composer test    # PHPUnit (the integration tests skip without their variables)
composer stan    # PHPStan, analysing at the PHP 8.1 floor
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the toolchain requirements and conventions. The integration environment variables are listed above.

### Release archive

`tools/release.sh VERSION` builds `dist/agency-programme-whmcs-VERSION.zip` from the tag `vVERSION` (not from the working tree) with `modules/addons/agencydiscount/`, `LICENSE`, `README.md` and `CHANGELOG.md` only, checks the archive's file list against the files tracked at the tag, and writes a `.sha256` beside it. It refuses to run when the tag does not exist, the working tree has uncommitted changes, or `Module::VERSION` at the tag differs from `VERSION`. Pushing a `v*` tag runs the same script in GitHub Actions (`.github/workflows/release.yml`) and publishes the archive and checksum as a GitHub release.

## Support this project

Agency Programme for WHMCS is free software from Noiz. If it is useful to you, the main way to support its development is a donation in Monero (XMR):

```text
44BtMn9izxH8mK2yFbSdY6Di7TNobkLbnHdZ6gZQjukCME5vsNhtPRtH4TcVkDHKHLhSpAJbsjv8gCdYuSZVMpXgMkUC1hV
```

You can also help by reporting bugs, sending fixes or starring the repository.

## Licence

AGPL-3.0-or-later. Copyright (C) 2026 Noiz. See `LICENSE`.
