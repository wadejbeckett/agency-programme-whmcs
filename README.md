# Agency Programme for WHMCS

Agency Programme is a WHMCS addon that runs a programme for approved agency clients. An administrator marks a client as an approved agency on the client profile, the module measures the client's qualifying monthly spend and places the client in a tier, and each tier carries a benefit. Today that benefit is a percentage discount on the client's qualifying hosting services and add-ons, applied automatically as one named negative line on each new invoice. No service price is ever changed. The module has a dry-run mode, an admin overview, a full change log and a command-line rehearsal, so the numbers can be checked before a single invoice is touched.

Licensed under the AGPL-3.0-or-later. Project home: https://github.com/wadejbeckett/agency-programme-whmcs

## What it replaces and where it is going

The module replaces hand-set promotion codes and client-group discounts for agency clients: the approval lives on the client profile, the tier follows the client's actual monthly spend, and the discount is visible as its own invoice line rather than hidden in a service price. The tier engine and the approval flag are the base for what comes next: agency applications from the client area, reporting on the programme, and an agency dashboard.

## How it works

- **Approval.** A client only qualifies once an administrator ticks "Approved agency" on the client's Profile tab in the WHMCS admin area. Unapproved clients are never discounted.
- **Qualifying spend.** The module adds up the recurring amount of every counted service and add-on, each converted to a monthly equivalent (a 12-month annual price counts as one twelfth). Which items count is configuration: product groups, individual products, add-on ids and statuses.
- **Tiers.** Tiers are defined per currency. A client gets the highest tier whose threshold the monthly spend is strictly above: spend of exactly 5,000 against a threshold of 5,000 does not qualify. A client in a currency with no tiers gets no discount.
- **Start date.** Each agency has an optional "Discount starts on" date on the client profile. It is compared with each invoice's due date (an invoice without a due date is compared with the day it is created): an invoice due before the start date gets no discount line and the module logs `not_yet_effective`; an invoice due on or after it is discounted, even when WHMCS generates it in advance, before the start date. The spend and tier are calculated and shown in the admin pages either way. Leave it empty for the discount to apply immediately.
- **The invoice line.** When WHMCS creates an invoice (the `InvoiceCreation` hook), the module recalculates the client's spend and tier, totals the discountable lines, and adds one negative line of type `AgencyDiscount` named by the "Invoice line text" setting, carrying the invoice's payment method. WHMCS is documented to recalculate the invoice totals from its lines afterwards; see [What has and has not been verified](#what-has-and-has-not-been-verified). The module records each invoice it discounts or dry-runs, so an invoice never receives more than one discount line.
- **What is discounted.** The Hosting and Addon lines of counted services and add-ons. Not discounted: a service's Hosting line when the same invoice also carries that service's promotion discount (a `PromoHosting` line), unless "Stack with promotions" is on. Such services still count toward spend, and once a promotion no longer reduces the service's invoices, the service is discounted again. Upgrades, domains, setup fees and manual items are never discounted. The discount line is taxed if any discounted line was taxed.
- **New orders.** WHMCS invoices a new order while the ordered service and add-ons are still Pending, so they are not yet in the counted spend. With "Discount new orders" on (the default), the Hosting and Addon lines of the client's pending qualifying items on that invoice are discounted as well, and the tier for that invoice is chosen on the counted monthly spend plus the monthly equivalent of those pending items, so a first large order can earn a tier on its own. The promotion rule above applies to pending lines too. The spend and tier stored for the agency and shown in the admin pages stay on counted items; the order's items join them once they are Active. With the setting off, a new order's invoice gets no discount and the discount starts with the first renewal. A status in "Counted statuses" always takes the ordinary route, so adding Pending there counts pending items toward spend everywhere and never counts a line twice.
- **Annual and longer cycles.** An annual service is invoiced once a year and receives whatever tier the client holds on the day that invoice is created.
- **Recalculation.** Spend and tier are also refreshed before the cron invoicing run and by the daily cron, and on demand from the admin overview.
- **What is left alone.** Nothing in `tblhosting` or `tblhostingaddons` is modified. The client area keeps showing the undiscounted recurring prices; the discount appears only on invoices.

### Rounding and money

All amounts are handled as decimal strings with `bcmath`, never as floats. Monthly equivalents and discounts are rounded half-up to two decimal places; each item's monthly equivalent is rounded before the items are summed. Tier thresholds are strictly-above comparisons on the two-decimal monthly total. A billing cycle the module does not recognise (for example one-time or free items) has a monthly equivalent of 0.00.

## Requirements

- WHMCS 9.x (8.x on PHP 8.1 or newer is untested)
- PHP 8.1 or newer, with the `bcmath` and `json` extensions

## Install

1. Copy `modules/addons/agencydiscount` into the WHMCS installation's `modules/addons/` directory.
2. In the admin area, activate it under System Settings > Addon Modules. Activation creates three tables (`mod_agencydiscount_clients`, `mod_agencydiscount_log`, `mod_agencydiscount_invoices`) and starts in dry-run mode.
3. Grant the admin role groups that should see the module access to it (Configure on the same page).
4. Open Addons > Agency Programme > Settings and set the tiers, the qualifying groups, products and add-ons, and the statuses (see below). `profiles/noiz.example.json` shows one complete configuration.
5. Approve agencies: open a client, go to the Profile tab and tick "Approved agency" under "Agency discount". Add a note and a start date if wanted.
6. Check the Overview and Configuration check pages while dry-run is on. The overview shows each agency's spend, tier, next invoice and discount.
7. When the numbers are right, press "Switch to live" on the Settings tab.

## Configuration

Addons > Agency Programme > **Settings** is where the module is configured. It saves into the same addon settings the engine reads (the keys below), so the raw form under System Settings > Addon Modules > Agency Programme > Configure and the CLI profile stay valid; that raw form is the advanced route and its fields say so.

The Settings tab has:

- **Mode card.** Whether dry-run is on and what that means, the last recalculation, how many invoices the module has checked since activation, and a "Switch to live" / "Switch to dry-run" button.
- **Discount tiers.** One editable table per WHMCS currency: the monthly spend each tier is above (0.00 allowed) and its percentage, "+ Add tier", "Remove", a band preview with a worked example per band, and "Copy <default> tiers to <code> at the WHMCS rate", which fills a currency's rows from the default currency's at WHMCS's exchange rate, rounded to two places, without saving.
- **What counts.** Product groups as checkboxes with the number of active services in each (hidden groups last), products by group and add-ons as filterable checkbox lists. An id the configuration names but WHMCS no longer has stays ticked and flagged so it can be removed.
- **Counted statuses.** Checkboxes; Pending is normally left to "Discount new orders".
- **Behaviour.** The four switches with one-line explanations and the invoice line text with a rendered example.
- **Preview impact** validates the form and shows each approved agency's qualifying spend, tier and next-invoice discount under the saved settings and under the unsaved values, without saving. **Save settings** validates, reports problems inline (nothing is saved while there are any) and logs `settings_changed` with the keys that changed, in the module change log and the WHMCS activity log. Every POST carries the WHMCS admin token.

Defaults are those in `Config::defaults()`. Yes/no settings are stored as `on` when ticked. The module reads `on`, `yes`, `1` and `true` as yes and empty, `off`, `0` and `no` as no; any other value is read as the safe choice: dry-run, activity log and keep data on, stacking and new-order discounts off.

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
| Invoice line text | `description` | `Agency discount {percent}% on {count} qualifying items` | Text of the discount line. Placeholders: `{percent}`, `{count}`. Must not be empty. |
| Write to activity log | `activity_log` | on | Also record applied discounts and tier changes in the WHMCS activity log. Settings and mode changes are always recorded there. |
| Keep data on deactivate | `keep_data` | off | Keep the module tables when the module is deactivated. |

An invalid configuration (bad JSON, a non-numeric id, no statuses) is reported at the top of the addon page and, once per request, in the WHMCS activity log. While it is invalid, no discount is applied and invoicing carries on as normal.

### Tiers format

`tiers` is a JSON object keyed by three-letter uppercase currency code. Each currency lists tiers in ascending order of `above`, the monthly spend (in that currency) the client must exceed, and `percent`, the discount from above 0 up to 100. A first tier with `"above": 0` gives every client with any qualifying spend that discount.

```json
{"USD":[{"above":500,"percent":5},{"above":1000,"percent":10}]}
```

With this setting a client in USD with qualifying monthly spend of 500.00 gets no discount, 500.01 gets 5% and above 1000.00 gets 10%. Add one key per currency in use; a currency without an entry gets no discount, and the Configuration check page flags it.

## Admin page

Addons > Agency Programme has four tabs (Overview, Settings, Change log, Configuration check) plus a Detail link per agency.

- **Overview.** One row per approved agency: client, currency, qualifying monthly spend, tier, number of counted items, the next invoice with its discount, the spend still needed for the next tier, whether the discount is active or starts on a date, when it was last recalculated, and a link to the detail. "Recalculate now" refreshes every agency. Viewing the page changes nothing.
- **Agency detail.** The counted services and add-ons with their cycle, amount, monthly equivalent, next invoice date and promotion flag, the upcoming invoices in the next 35 days with the base and the discount each would receive, and a link to that client's change log. The upcoming invoices are an estimate from the next invoice dates: they include services on a promotion, because whether a promotion still reduces an invoice is only known from that invoice's lines, and dates before the agency's start date show no discount. Pending new-order items are not listed and not estimated; they are only discounted on the order invoice itself.
- **Settings.** The configuration, described under [Configuration](#configuration).
- **Change log.** Every event, newest first, filterable by client, with its actor and detail.
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
| `applied` | A discount line was added to an invoice. |
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

## Rehearsal CLI

The command-line tool replays the module against a copy of the data without writing anything, so tiers and qualifying items can be tuned before activation.

1. Export a snapshot from a copy of the WHMCS database (read-only `SELECT`s, needs the `mariadb` client):

   ```bash
   tools/snapshot.sh OUTDIR DATABASE [mariadb client options...]
   ```

   A snapshot contains client data. Keep it outside the repository and never commit it.

2. Rehearse with a settings file such as `profiles/noiz.example.json`:

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

The hooks were exercised against a MariaDB copy of a WHMCS 9.0.8 database through Illuminate's Capsule, not on a licensed WHMCS installation. These points remain to be confirmed on a real install before relying on the module in production:

- How WHMCS's input sanitiser encodes the profile fields (the note and start date), which the module decodes before storing, and the addon settings (notably the tiers JSON), which the module decodes when it reads them.
- The output of `generate_token()`, used for the CSRF field on the overview's "Recalculate now" form and the Settings tab's forms.
- That the Settings tab's inline script (currency sub-tabs, add and remove tier rows, name filters) runs unchanged inside the WHMCS admin template; the server accepts any number of rows, so the page works without it.
- How the yes/no `Default` values render in the addon settings screen.
- That `agencydiscount_deactivate()` runs before WHMCS deletes the module's settings rows; this decides whether "Keep data on deactivate" is honoured.
- That the discount line, which copies the invoice's `paymentmethod`, displays correctly in WHMCS invoice views.
- That WHMCS recalculates invoice totals after the `InvoiceCreation` hook returns. This is documented WHMCS behaviour but has not been observed here.

This is why the module ships with dry-run on. Run it in dry-run on the real installation first, then check the first live invoice.

## Uninstall

Deactivating the module under System Settings > Addon Modules drops its three tables, and with them the agency approvals, the change log and the invoice records, unless "Keep data on deactivate" is on. Back up `mod_agencydiscount_clients`, `mod_agencydiscount_log` and `mod_agencydiscount_invoices` first (for example with `mysqldump`). Discount lines on invoices already issued stay as they are. No service price was ever altered, so there is nothing to restore.

## Limitations

- Invoices are discounted when they are created. An invoice edited by hand, or created before the module was active, is not recomputed.
- An approved agency's new orders are discounted only while "Discount new orders" is on (the default). With it off, WHMCS invoices the order while the service is still Pending, so the order invoice carries no discount and the discount starts with the first renewal.
- Invoices built by hand in the admin area start empty when WHMCS creates them, so the hook sees no qualifying lines and they are never discounted.
- An invoice created while dry-run is on is recorded as processed and is not discounted retroactively when dry-run is switched off.
- Suspended or otherwise non-active services do not count unless their status is added to "Counted statuses".
- A currency without tiers gets no discount.
- There is no client-facing application flow: an administrator approves agencies.
- The client area shows undiscounted recurring prices; the discount appears on the invoice.

## Development

```bash
composer install
composer test    # PHPUnit (the integration tests skip without their variables)
composer stan    # PHPStan, analysing at the PHP 8.1 floor
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the toolchain requirements and conventions. The integration environment variables are listed above.

## Licence

AGPL-3.0-or-later. Copyright (C) 2026 Noiz. See `LICENSE`.
