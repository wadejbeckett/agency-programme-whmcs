# Profiles

A profile is the set of Agency Programme addon settings written as a JSON object. The keys are the setting names from the module configuration (`tiers`, `product_groups`, `products`, `addons`, `statuses`, `dry_run`, `stack_with_promotions`, `new_orders`, `description`, `activity_log`, `delete_data`). Checkbox settings use `"on"` for yes and `""` for no, as WHMCS stores them.

A profile has two uses:

- `bin/agency-discount rehearse --settings profiles/example.json ...` runs a rehearsal against a database snapshot with those settings, without touching the WHMCS configuration.
- It is a reference when filling in the addon configuration under System Settings > Addon Modules: copy each value into the matching field.

Every profile in this directory must parse, use only known keys, ship with dry-run on and define at least one currency's tiers. `tests/ProfilesTest.php` enforces this.

## example.json

A complete example to start from. The tiers are in USD and apply to the qualifying monthly spend: any qualifying spend (above 0) gives 5%, above 1,000 gives 10%, above 1,500 gives 15% and above 2,000 gives 20%.

The numeric ids are placeholders:

| Setting | Ids | Meaning |
| --- | --- | --- |
| `product_groups` | 1, 2 | Product groups whose services count |
| `products` | 10, 11 | Individual products outside those groups that count |
| `addons` | 3, 4 | Product add-ons that count |

Ids differ on every WHMCS installation. Before using the profile, replace every id with the ones from that installation (System Settings > Products/Services shows them), add one tiers entry per currency in use, then open the addon's Configuration check page to confirm each id resolves to the intended group, product or add-on.

`statuses` is `Active`: suspended, cancelled and terminated services do not count. `new_orders` is on, so an approved agency's new-order invoice is discounted too, at the tier its counted spend plus the order earns.

The example ships with dry-run on. Leave it on until the admin overview shows the numbers you expect.
