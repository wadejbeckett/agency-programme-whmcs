<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

$_ADDONLANG['title'] = 'Agency Programme';
$_ADDONLANG['overview'] = 'Overview';
$_ADDONLANG['settings'] = 'Settings';
$_ADDONLANG['log'] = 'Change log';
$_ADDONLANG['check'] = 'Configuration check';
$_ADDONLANG['dry_run_banner'] = 'Dry-run mode is on: discounts are calculated and logged but no invoice is changed.';

// Settings tab: mode card
$_ADDONLANG['mode'] = 'Mode';
$_ADDONLANG['mode_dry_run'] = 'Dry-run';
$_ADDONLANG['mode_live'] = 'Live';
$_ADDONLANG['mode_dry_run_help'] = 'Every invoice is checked and the discount it would get is recorded in the change log, but no invoice is changed. Check the Overview, then switch to live.';
$_ADDONLANG['mode_live_help'] = 'Approved agencies receive their discount as a line on each new invoice.';
$_ADDONLANG['mode_switch_live'] = 'Switch to live';
$_ADDONLANG['mode_switch_dry_run'] = 'Switch to dry-run';
$_ADDONLANG['mode_confirm_live'] = 'Switch to live? From now on new invoices of approved agencies receive a discount line.';
$_ADDONLANG['mode_last_recalculation'] = 'Last recalculation';
$_ADDONLANG['mode_invoices_checked'] = 'Invoices checked since activation';
$_ADDONLANG['mode_switched'] = 'Switched to %s mode.';
$_ADDONLANG['mode_unchanged'] = 'Already in %s mode.';
$_ADDONLANG['never'] = 'never';

// Settings tab: tiers
$_ADDONLANG['tiers'] = 'Discount tiers';
$_ADDONLANG['tiers_help'] = 'Per currency. An agency gets the highest tier whose threshold its qualifying monthly spend is strictly above; a first tier above 0.00 gives every agency with any qualifying spend that discount. Thresholds must ascend.';
$_ADDONLANG['tiers_default_currency'] = 'default currency';
$_ADDONLANG['tiers_not_in_whmcs'] = 'not a WHMCS currency';
$_ADDONLANG['tiers_none'] = 'No tiers: agencies in this currency get no discount.';
$_ADDONLANG['tier'] = 'Tier';
$_ADDONLANG['tier_above'] = 'Monthly spend above';
$_ADDONLANG['tier_percent'] = 'Discount %';
$_ADDONLANG['tier_add'] = '+ Add tier';
$_ADDONLANG['tier_remove'] = 'Remove';
$_ADDONLANG['tiers_copy'] = 'Copy %1$s tiers to %2$s at the WHMCS rate (%3$s)';
$_ADDONLANG['tiers_copied'] = '%2$s tiers filled from %1$s at rate %3$s. Not saved yet: check them, then save.';
$_ADDONLANG['tiers_copy_source_invalid'] = 'Fix the %s tiers before copying them.';
$_ADDONLANG['tiers_copy_source_empty'] = '%s has no tiers to copy.';
$_ADDONLANG['tiers_copy_no_rate'] = '%s has no exchange rate in WHMCS.';
$_ADDONLANG['tiers_preview'] = 'Band preview';
$_ADDONLANG['tiers_band'] = 'Qualifying monthly spend';
$_ADDONLANG['tiers_example'] = 'Example';
$_ADDONLANG['tiers_example_line'] = 'a %1$s invoice is reduced by %2$s';
$_ADDONLANG['tiers_band_from_to'] = 'above %1$s up to %2$s';
$_ADDONLANG['tiers_band_from'] = 'above %s';

// Settings tab: what counts
$_ADDONLANG['counts'] = 'What counts';
$_ADDONLANG['counts_help'] = 'Services in a ticked product group, services of a ticked product and ticked add-ons count toward qualifying spend and receive the discount. Everything else is left alone.';
$_ADDONLANG['groups'] = 'Product groups';
$_ADDONLANG['groups_active'] = '%d active';
$_ADDONLANG['groups_hidden'] = 'hidden';
$_ADDONLANG['products'] = 'Products';
$_ADDONLANG['products_help'] = 'Individual products outside the ticked groups. A product in a ticked group already counts.';
$_ADDONLANG['addons'] = 'Add-ons';
$_ADDONLANG['filter'] = 'Filter by name';
$_ADDONLANG['not_found'] = 'not found in WHMCS';
$_ADDONLANG['group_unknown'] = 'Group #%d';
$_ADDONLANG['statuses'] = 'Counted statuses';
$_ADDONLANG['statuses_help'] = 'Services and add-ons in these statuses count. Pending items are handled by "Discount new orders" below; tick Pending only to count them toward spend everywhere.';

// Settings tab: behaviour
$_ADDONLANG['behaviour'] = 'Behaviour';
$_ADDONLANG['switch_new_orders'] = 'Discount new orders';
$_ADDONLANG['switch_new_orders_help'] = 'Also discount an approved agency\'s order invoice while its items are still Pending; the tier is chosen on the counted spend plus the ordered items.';
$_ADDONLANG['switch_stack_with_promotions'] = 'Stack with promotions';
$_ADDONLANG['switch_stack_with_promotions_help'] = 'Also discount a service\'s line when the same invoice carries that service\'s promotion discount.';
$_ADDONLANG['switch_activity_log'] = 'Write to activity log';
$_ADDONLANG['switch_activity_log_help'] = 'Also record applied discounts and tier changes in the WHMCS activity log. Settings and mode changes are always recorded.';
$_ADDONLANG['switch_keep_data'] = 'Keep data on deactivate';
$_ADDONLANG['switch_keep_data_help'] = 'Keep the module tables (approvals, change log, invoice records) when the module is deactivated.';
$_ADDONLANG['description'] = 'Invoice line text';
$_ADDONLANG['description_help'] = 'Placeholders: {percent} and {count}.';
$_ADDONLANG['description_example'] = 'Example';

// Settings tab: impact and actions
$_ADDONLANG['impact'] = 'Impact';
$_ADDONLANG['impact_help'] = 'Each approved agency under the saved settings and under the values on this form.';
$_ADDONLANG['impact_tier_now'] = 'Tier now';
$_ADDONLANG['impact_tier_after'] = 'Tier after';
$_ADDONLANG['impact_discount_now'] = 'Next invoice discount now';
$_ADDONLANG['impact_discount_after'] = 'after';
$_ADDONLANG['impact_none'] = 'No approved agencies yet.';
$_ADDONLANG['impact_unchanged'] = 'No agency changes tier under these values.';
$_ADDONLANG['preview'] = 'Preview impact';
$_ADDONLANG['save'] = 'Save settings';
$_ADDONLANG['saved'] = 'Settings saved. Changed: %s.';
$_ADDONLANG['saved_nothing'] = 'No changes to save.';
$_ADDONLANG['problems'] = 'The settings were not saved. Fix these and try again:';
$_ADDONLANG['advanced_json'] = 'Advanced: edit as JSON';
$_ADDONLANG['advanced_json_help'] = 'The raw settings form under System Settings > Addon Modules stores the same keys.';
