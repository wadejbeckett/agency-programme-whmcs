<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Loads the module library. No autoloader on purpose: WHMCS modules are
 * deployed by file copy and must work without a Composer vendor directory.
 * Keep this list in dependency order.
 */

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/Tier.php';
require_once __DIR__ . '/Tiers.php';
require_once __DIR__ . '/ConfigException.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/ArrayRepository.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/SpendReport.php';
require_once __DIR__ . '/SpendCalculator.php';
require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/SystemClock.php';
require_once __DIR__ . '/FrozenClock.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/NullActivityLog.php';
require_once __DIR__ . '/RecordingActivityLog.php';
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
require_once __DIR__ . '/Admin/SettingsForm.php';
require_once __DIR__ . '/Admin/SettingsTab.php';
require_once __DIR__ . '/Admin/OverviewTab.php';
require_once __DIR__ . '/Admin/Page.php';
require_once __DIR__ . '/SnapshotLoader.php';
require_once __DIR__ . '/Cli.php';
