# Profiles

A profile is the set of Agency Programme addon settings written as a JSON object. The keys are the setting names from the module configuration (`tiers`, `product_groups`, `products`, `addons`, `statuses`, `dry_run`, `stack_with_promotions`, `new_orders`, `description`, `activity_log`, `keep_data`). Checkbox settings use `"on"` for yes and `""` for no, as WHMCS stores them.

A profile has two uses:

- `bin/agency-discount rehearse --settings profiles/noiz.example.json ...` runs a rehearsal against a database snapshot with those settings, without touching the WHMCS configuration.
- It is a reference when filling in the addon configuration under System Settings > Addon Modules: copy each value into the matching field.

Every profile in this directory must parse, use only known keys, ship with dry-run on and define at least one currency's tiers. `tests/ProfilesTest.php` enforces this.

## noiz.example.json

The settings Noiz starts from. The tiers are in ZAR and apply to the qualifying monthly spend: above 5,000 a month gives 5%, above 10,000 gives 10%, above 15,000 gives 15% and above 20,000 gives 20%.

The numeric ids refer to the installation the example was written for:

| Setting | Id | Meaning |
| --- | --- | --- |
| `product_groups` | 1 | Legacy |
| `product_groups` | 23 | Premium Shared |
| `product_groups` | 3 | Reseller Hosting |
| `product_groups` | 6 | Cloud |
| `products` | 160 | Edge Security (Security group) |
| `products` | 161 | Email Security (Security group) |
| `addons` | 12 | Advanced Email Security |
| `addons` | 15 | Cloudflare Edge Security |

Ids differ on every WHMCS installation. Before using a profile elsewhere, replace every id with the ones from that installation (System Settings > Products/Services shows them), then open the addon's Configuration check page to confirm each id resolves to the intended group, product or add-on.

So the Legacy, Premium Shared, Reseller (3) and Cloud (6) groups count, as do the standalone Security-group products 160 Edge Security and 161 Email Security and add-ons 12 and 15. `statuses` is `Active`: suspended, cancelled and terminated services do not count. `new_orders` is on, so an approved agency's new-order invoice is discounted too, at the tier its counted spend plus the order earns.

The example ships with dry-run on. Leave it on until the admin overview shows the numbers you expect.
