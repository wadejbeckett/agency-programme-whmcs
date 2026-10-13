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

// Overview tab
$_ADDONLANG['recalculate'] = 'Recalculate now';
$_ADDONLANG['recalculated'] = 'Recalculated %d agencies.';
$_ADDONLANG['client_missing'] = 'Client %d no longer exists in WHMCS.';
$_ADDONLANG['no_agencies'] = 'No approved agencies yet. Approve a client on its profile tab.';
$_ADDONLANG['no_matches'] = 'No agencies match this filter.';
$_ADDONLANG['tile_agencies'] = 'Approved agencies';
$_ADDONLANG['tile_agencies_detail'] = '%1$d at a tier · %2$d waiting for a start date';
$_ADDONLANG['tile_spend'] = 'Qualifying spend per month';
$_ADDONLANG['tile_discount'] = 'Discount in the next %d days';
$_ADDONLANG['tile_discount_dry_run'] = 'Would be applied: dry-run is on';
$_ADDONLANG['tile_discount_live'] = 'On the invoices expected in this window';
$_ADDONLANG['tile_mode'] = 'Mode';
$_ADDONLANG['filter_all'] = 'All';
$_ADDONLANG['filter_tier'] = 'At a tier';
$_ADDONLANG['filter_waiting'] = 'Not yet started';
$_ADDONLANG['filter_no_tiers'] = 'No tiers for currency';
$_ADDONLANG['filter_name'] = 'Client name';
$_ADDONLANG['filter_apply'] = 'Filter';
$_ADDONLANG['col_client'] = 'Client';
$_ADDONLANG['col_currency'] = 'Currency';
$_ADDONLANG['col_spend'] = 'Qualifying monthly spend';
$_ADDONLANG['col_tier'] = 'Tier';
$_ADDONLANG['col_to_next'] = 'To next tier';
$_ADDONLANG['col_next_invoice'] = 'Next invoice';
$_ADDONLANG['col_starts'] = 'Starts';
$_ADDONLANG['col_recalculated'] = 'Recalculated';
$_ADDONLANG['col_detail'] = 'Detail';
$_ADDONLANG['detail'] = 'Detail';
$_ADDONLANG['no_tiers_for'] = 'no tiers for %s';
$_ADDONLANG['no_tier'] = 'No tier';
$_ADDONLANG['tier_badge'] = 'Tier %1$d (%2$s%%)';
$_ADDONLANG['top_tier'] = 'top tier';
$_ADDONLANG['to_next_tier'] = '%1$s to tier %2$d (%3$s%%)';
$_ADDONLANG['next_invoice_none'] = 'none in %d days';
$_ADDONLANG['next_invoice_no_discount'] = '%1$s: no discount on %2$s';
$_ADDONLANG['next_invoice'] = '%1$s: %2$s off %3$s';
$_ADDONLANG['starts'] = 'starts %s';
$_ADDONLANG['active'] = 'active';
$_ADDONLANG['active_since'] = 'active since %s';
$_ADDONLANG['attention'] = 'Needs attention';
$_ADDONLANG['attention_none'] = 'Nothing needs attention.';
$_ADDONLANG['attention_dry_run'] = 'Dry-run mode is on.';
$_ADDONLANG['attention_dry_run_days'] = 'Dry-run mode has been on for %d days.';
$_ADDONLANG['attention_dry_run_at_least'] = 'Dry-run mode has been on for at least %d days.';
$_ADDONLANG['attention_no_tiers_one'] = '%1$s has no tiers: %2$d approved agency gets no discount.';
$_ADDONLANG['attention_no_tiers'] = '%1$s has no tiers: %2$d approved agencies get no discount.';
$_ADDONLANG['attention_near_tier'] = '%1$s is %2$s from tier %3$d (%4$s%%).';
$_ADDONLANG['activity'] = 'Recent activity';
$_ADDONLANG['activity_none'] = 'No activity yet.';
$_ADDONLANG['activity_all'] = 'Full change log';
$_ADDONLANG['log_summary'] = 'Summary';

// Admin home widget
$_ADDONLANG['widget_description'] = 'Approved agencies, their qualifying spend and the discount expected in the next 35 days.';
$_ADDONLANG['widget_agencies'] = 'Agencies';
$_ADDONLANG['widget_spend'] = 'Spend per month';
$_ADDONLANG['widget_discount'] = 'Next %d days';
$_ADDONLANG['widget_last_check'] = 'Last cron check';
$_ADDONLANG['widget_open'] = 'Open Agency Programme';
$_ADDONLANG['widget_unavailable'] = 'Agency Programme: unavailable (see activity log)';
$_ADDONLANG['widget_none'] = 'No approved agencies yet.';

// Admin client summary card and profile tab
$_ADDONLANG['summary_status'] = 'Status';
$_ADDONLANG['summary_spend'] = 'Qualifying monthly spend';
$_ADDONLANG['summary_next_invoice'] = 'Next invoice';
$_ADDONLANG['summary_to_next'] = 'To the next tier';
$_ADDONLANG['summary_dry_run'] = 'Dry-run mode is on: the discount is calculated and logged, but not yet added to invoices.';
$_ADDONLANG['summary_waiting'] = 'Approved; the discount starts on %s.';
$_ADDONLANG['summary_counted_items'] = 'Counted items';
$_ADDONLANG['summary_change_log'] = 'Change log';
$_ADDONLANG['summary_profile'] = 'Approval on the profile tab';
$_ADDONLANG['profile_approved'] = 'Approved agency';
$_ADDONLANG['profile_approved_by'] = 'Approved by %1$s on %2$s';
$_ADDONLANG['profile_note'] = 'Note';
$_ADDONLANG['profile_starts'] = 'Discount starts on';
$_ADDONLANG['profile_starts_help'] = 'Leave empty to start immediately, or set the first day the discount applies: invoices due before it are not discounted, so a date gives notice before the first discounted invoice.';
$_ADDONLANG['profile_right_now'] = 'Right now';
$_ADDONLANG['widget_denied'] = 'Agency Programme is not available to your admin role.';
