<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use AgencyDiscount\Admin\Html;
use AgencyDiscount\Admin\Page;
use AgencyDiscount\CapsuleRepository;
use AgencyDiscount\ClientArea\PageData;
use AgencyDiscount\Config;
use AgencyDiscount\Hooks;
use AgencyDiscount\Module;
use AgencyDiscount\Schema;
use AgencyDiscount\SystemClock;
use AgencyDiscount\Upgrade;
use AgencyDiscount\WhmcsActivityLog;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

/**
 * The WHMCS Configure form (System Settings > Addon Modules > Agency Programme): the access list,
 * which WHMCS adds itself, and the housekeeping settings. Tiers, qualifying items, statuses, the
 * mode, the invoice line text and the behaviour switches are edited on the addon's Settings tab
 * only; their rows in tbladdonmodules are read by Config::fromSettings and a missing row takes
 * its default. WHMCS's Configure save writes the declared fields and leaves other rows alone (seen
 * on a live install: a row dropped from this list in 0.2.1 survived later saves), so no setting
 * has two homes and a stale Configure form can no longer write old values back.
 *
 * @return array<string, mixed>
 */
function agencydiscount_config(): array
{
    $d = Config::defaults();
    return [
        'name' => 'Agency Programme',
        'description' => 'A programme for approved agency clients: approval on the client profile, tiered benefits by qualifying monthly spend, today a percentage discount applied automatically as a named invoice line. Tiers, qualifying items and the mode are set under Addons > Agency Programme > Settings; the module starts in dry-run mode.',
        'version' => Module::VERSION,
        'author' => 'Noiz',
        'language' => 'english',
        'fields' => [
            'activity_log' => ['FriendlyName' => 'Write to activity log', 'Type' => 'yesno', 'Default' => 'yes', 'Description' => 'Also record applied discounts and tier changes in the WHMCS activity log. Settings and mode changes are always recorded.'],
            'log_retention_months' => ['FriendlyName' => 'Change log retention (months)', 'Type' => 'text', 'Size' => '5', 'Default' => $d['log_retention_months'], 'Description' => 'Change log entries older than this are removed by the daily cron. 0 keeps the log forever. Invoice records and approvals are never removed by this.'],
            'delete_data' => ['FriendlyName' => 'Delete module data on deactivate', 'Type' => 'yesno', 'Description' => 'The tables with the agency approvals, the change log and the invoice records are removed on deactivate only when this is ticked.'],
        ],
    ];
}

/** @return array{status: string, description: string} */
function agencydiscount_activate(): array
{
    try {
        $repo = new CapsuleRepository();
        Schema::install($repo);
        if (!isset($repo->settings()['dry_run'])) {
            // The mode row is the Settings tab's; stored here so a fresh install starts in dry-run by a stored value, not a default.
            $repo->saveSettings(['dry_run' => 'on']);
        }
        return ['status' => 'success', 'description' => 'Agency Programme activated in dry-run mode. Set the tiers and qualifying items under Addons > Agency Programme > Settings, approve agencies on their client profiles, then switch to live there.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Agency Programme could not create its tables: ' . $e->getMessage()];
    }
}

/** @return array{status: string, description: string} */
function agencydiscount_deactivate(): array
{
    try {
        // Only an explicit yes deletes: a missing row (WHMCS may remove the settings first) or an unexpected value keeps the data.
        $settings = (new CapsuleRepository())->settings();
        if (!Config::yesNo($settings['delete_data'] ?? '', false)) {
            return ['status' => 'success', 'description' => 'Agency Programme deactivated. Its tables (approvals, change log, invoice records) were kept; tick "Delete module data on deactivate" first to remove them. Discount lines on issued invoices are unchanged.'];
        }
        Schema::uninstall(new CapsuleRepository());
        return ['status' => 'success', 'description' => 'Agency Programme deactivated and its tables removed. Discount lines on issued invoices are unchanged; no service price was ever altered.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Agency Programme deactivation problem: ' . $e->getMessage()];
    }
}

/**
 * Called by WHMCS when the installed version ($vars['version']) is older than Module::VERSION.
 * Upgrade::run branches on that version: from below 0.2.1 it reshapes the invoice records table and
 * replaces the old default invoice line text; from below 0.3.0 it adds the client index and deletes
 * the retired keep_data row. Each change goes to the activity log, as does each note (what moved
 * without a stored value changing); a step already applied is skipped, so a re-run is safe.
 *
 * @param array<string, mixed> $vars
 * @return array{status: string, description: string}
 */
function agencydiscount_upgrade(array $vars): array
{
    $from = is_scalar($vars['version'] ?? null) ? (string) $vars['version'] : 'unknown';
    try {
        $changes = Upgrade::run(new CapsuleRepository(), $from);
        $notes = Upgrade::notes($from);
        foreach ($changes as $change) {
            \logActivity('Agency Programme upgrade to ' . Module::VERSION . ': ' . $change);
        }
        foreach ($notes as $note) {
            \logActivity('Agency Programme upgrade to ' . Module::VERSION . ', note: ' . $note);
        }
        $summary = $changes === [] ? 'no schema change' : count($changes) . ' change' . (count($changes) === 1 ? '' : 's');
        \logActivity('Agency Programme upgraded from ' . $from . ' to ' . Module::VERSION . ' (' . $summary . ')');
        return ['status' => 'success', 'description' => 'Agency Programme upgraded from ' . $from . ' to ' . Module::VERSION . '. ' . ($changes === [] ? 'No tables changed.' : implode('; ', $changes) . '.') . ($notes === [] ? '' : ' ' . implode('; ', $notes) . '.')];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Agency Programme upgrade problem: ' . $e->getMessage()];
    }
}

/**
 * The addon page. When the stored settings do not parse, Page::forStoredSettings gives the repair
 * page: the problems in a banner, the Settings tab filled from the stored values, the Change log.
 *
 * @param array<string, mixed> $vars
 */
function agencydiscount_output(array $vars): void
{
    $moduleLink = (string) ($vars['modulelink'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        check_token('WHMCS.admin.default');
    }
    try {
        $page = Page::forStoredSettings(new CapsuleRepository(), new SystemClock(), new WhmcsActivityLog());
        $adminId = \WHMCS\Session::get('adminid');
        $actor = is_numeric($adminId) ? 'admin:' . (int) $adminId : 'admin';
        echo $page->render($moduleLink, $_GET, Page::decodeInput($_POST), $actor, generate_token());
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Agency Programme could not show this page: ' . Html::e(get_class($e) . ': ' . $e->getMessage()) . '</div>';
    }
}

/**
 * The client-area page (index.php?m=agencydiscount): the tier table with the client's tier marked,
 * what counts and what does not, the client's counted items with monthly equivalents and the
 * discount saved this year. Read-only, for approved agencies; anyone else sees a one-line notice.
 * A failure is logged and shown as that notice too, never as an error page.
 *
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function agencydiscount_clientarea(array $vars): array
{
    try {
        $data = PageData::build(Module::boot(), Hooks::clientId());
    } catch (\Throwable $e) {
        try {
            \logActivity('Agency Discount: client area: ' . get_class($e) . ': ' . $e->getMessage());
        } catch (\Throwable) {
            error_log('Agency Discount: client area: ' . $e->getMessage());
        }
        $data = ['approved' => false, 'text' => PageData::text(), 'link' => PageData::MODULE_PAGE];
    }
    $title = $data['text']['title'];
    return [
        'pagetitle' => $title,
        'breadcrumb' => [PageData::MODULE_PAGE => $title],
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'vars' => $data,
    ];
}
