<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use AgencyDiscount\Admin\Html;
use AgencyDiscount\Admin\Page;
use AgencyDiscount\CapsuleRepository;
use AgencyDiscount\Config;
use AgencyDiscount\ConfigException;
use AgencyDiscount\Module;
use AgencyDiscount\Schema;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

/** @return array<string, mixed> */
function agencydiscount_config(): array
{
    $d = Config::defaults();
    $tab = 'Edit on the Agency Programme › Settings tab (Addons > Agency Programme > Settings). This raw field stores the same value and is the advanced route.';
    return [
        'name' => 'Agency Programme',
        'description' => 'A programme for approved agency clients: approval on the client profile, tiered benefits by qualifying monthly spend, today a percentage discount applied automatically as a named invoice line. Start in dry-run mode, check the overview, then switch dry-run off.',
        'version' => Module::VERSION,
        'author' => 'Noiz',
        'language' => 'english',
        'fields' => [
            'tiers' => ['FriendlyName' => 'Tiers (JSON)', 'Type' => 'textarea', 'Rows' => '6', 'Cols' => '60', 'Default' => $d['tiers'], 'Description' => $tab . ' Per currency code: a list of {"above": monthly spend, "percent": discount}, ascending.'],
            'product_groups' => ['FriendlyName' => 'Qualifying product group IDs', 'Type' => 'text', 'Size' => '40', 'Default' => $d['product_groups'], 'Description' => $tab . ' Comma-separated group ids.'],
            'products' => ['FriendlyName' => 'Qualifying product IDs', 'Type' => 'text', 'Size' => '40', 'Default' => $d['products'], 'Description' => $tab . ' Comma-separated product ids outside the groups above.'],
            'addons' => ['FriendlyName' => 'Qualifying add-on IDs', 'Type' => 'text', 'Size' => '40', 'Default' => $d['addons'], 'Description' => $tab . ' Comma-separated product add-on ids.'],
            'statuses' => ['FriendlyName' => 'Counted statuses', 'Type' => 'text', 'Size' => '40', 'Default' => $d['statuses'], 'Description' => $tab . ' Comma-separated service and add-on statuses that count.'],
            'dry_run' => ['FriendlyName' => 'Dry-run mode', 'Type' => 'yesno', 'Default' => $d['dry_run'] === 'on' ? 'yes' : 'no', 'Description' => 'Calculate and log, but do not change any invoice. The Settings tab has a switch for this too.'],
            'stack_with_promotions' => ['FriendlyName' => 'Stack with promotions', 'Type' => 'yesno', 'Description' => 'Also discount a service\'s invoice line when the same invoice carries that service\'s promotion discount.'],
            'new_orders' => ['FriendlyName' => 'Discount new orders', 'Type' => 'yesno', 'Default' => $d['new_orders'] === 'on' ? 'yes' : 'no', 'Description' => 'Also discount an approved agency\'s new-order invoice, where the ordered items are still Pending. The tier is chosen on the current qualifying spend plus the ordered items.'],
            'description' => ['FriendlyName' => 'Invoice line text', 'Type' => 'text', 'Size' => '60', 'Default' => $d['description'], 'Description' => 'Placeholders: {percent}, {count}.'],
            'activity_log' => ['FriendlyName' => 'Write to activity log', 'Type' => 'yesno', 'Default' => 'yes', 'Description' => 'Also record applied discounts and tier changes in the WHMCS activity log.'],
            'keep_data' => ['FriendlyName' => 'Keep data on deactivate', 'Type' => 'yesno', 'Description' => 'Keep the module tables (approvals, log) when the module is deactivated.'],
        ],
    ];
}

/** @return array{status: string, description: string} */
function agencydiscount_activate(): array
{
    try {
        Schema::install(new CapsuleRepository());
        return ['status' => 'success', 'description' => 'Agency Programme activated in dry-run mode. Configure tiers and qualifying items, approve agencies on their client profiles, then switch dry-run off.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Agency Programme could not create its tables: ' . $e->getMessage()];
    }
}

/** @return array{status: string, description: string} */
function agencydiscount_deactivate(): array
{
    try {
        $settings = (new CapsuleRepository())->settings();
        if (Config::yesNo($settings['keep_data'] ?? '', true)) {
            return ['status' => 'success', 'description' => 'Agency Programme deactivated. Module tables kept as configured. Discount lines on issued invoices are unchanged.'];
        }
        Schema::uninstall(new CapsuleRepository());
        return ['status' => 'success', 'description' => 'Agency Programme deactivated and its tables removed. Discount lines on issued invoices are unchanged; no service price was ever altered.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Agency Programme deactivation problem: ' . $e->getMessage()];
    }
}

/**
 * 0.1.0 is the first release: CREATE TABLE IF NOT EXISTS is all an upgrade needs so far.
 *
 * @param array<string, mixed> $vars
 * @return array{status: string, description: string}
 */
function agencydiscount_upgrade(array $vars): array
{
    try {
        Schema::install(new CapsuleRepository());
        return ['status' => 'success', 'description' => 'Agency Programme upgraded to ' . Module::VERSION . '.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Agency Programme upgrade problem: ' . $e->getMessage()];
    }
}

/** @param array<string, mixed> $vars */
function agencydiscount_output(array $vars): void
{
    $moduleLink = (string) ($vars['modulelink'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        check_token('WHMCS.admin.default');
    }
    try {
        $module = Module::boot();
        $adminId = \WHMCS\Session::get('adminid');
        $actor = is_numeric($adminId) ? 'admin:' . (int) $adminId : 'admin';
        echo (new Page($module))->render($moduleLink, $_GET, Page::decodeInput($_POST), $actor, generate_token());
    } catch (ConfigException $e) {
        echo Page::configProblems($moduleLink, $e->problems());
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Agency Programme could not show this page: ' . Html::e(get_class($e) . ': ' . $e->getMessage()) . '</div>';
    }
}
