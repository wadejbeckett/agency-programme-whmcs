<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Loads the module library. No autoloader on purpose: WHMCS modules are
 * deployed by file copy and must work without a Composer vendor directory.
 * Keep this list in dependency order. Development-only classes (the in-memory
 * repository, the frozen clock, the recording activity log, the snapshot
 * loader and the CLI) live in bin/lib/ and are loaded by bin/lib/bootstrap.php,
 * so WHMCS never parses them and the release tree does not ship them.
 */

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/Tier.php';
require_once __DIR__ . '/Tiers.php';
require_once __DIR__ . '/ConfigException.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Upgrade.php';
require_once __DIR__ . '/SpendReport.php';
require_once __DIR__ . '/SpendCalculator.php';
require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/SystemClock.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/NullActivityLog.php';
require_once __DIR__ . '/WhmcsActivityLog.php';
require_once __DIR__ . '/Recalculator.php';
require_once __DIR__ . '/DiscountOutcome.php';
require_once __DIR__ . '/InvoiceDiscounter.php';
require_once __DIR__ . '/Sql.php';
require_once __DIR__ . '/CapsuleRepository.php';
require_once __DIR__ . '/Module.php';
require_once __DIR__ . '/Hooks.php';
require_once __DIR__ . '/ProfileTab.php';
require_once __DIR__ . '/Admin/Html.php';
require_once __DIR__ . '/Admin/Preview.php';
require_once __DIR__ . '/AgencyStatus.php';
require_once __DIR__ . '/LogEntry.php';
require_once __DIR__ . '/Admin/ConfigWarnings.php';
require_once __DIR__ . '/Admin/SettingsForm.php';
require_once __DIR__ . '/Admin/SettingsTab.php';
require_once __DIR__ . '/Admin/OverviewTab.php';
require_once __DIR__ . '/Admin/AgencyTab.php';
require_once __DIR__ . '/Admin/WidgetData.php';
require_once __DIR__ . '/Admin/ClientSummary.php';
require_once __DIR__ . '/ClientArea/PageData.php';
if (class_exists(\WHMCS\Module\AbstractWidget::class)) {
    require_once __DIR__ . '/Admin/HomeWidget.php'; // only inside WHMCS: the base class is not available to the tests or the CLI
}
require_once __DIR__ . '/Admin/Page.php';
