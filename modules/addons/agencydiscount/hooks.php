<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use AgencyDiscount\Hooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

add_hook('InvoiceCreation', 1, static function (array $vars): void {
    Hooks::invoiceCreation($vars);
});
add_hook('PreInvoicingGenerateInvoiceItems', 1, static function (): void {
    Hooks::recalculateAll('cron:pre-invoicing');
});
add_hook('DailyCronJob', 1, static function (): void {
    Hooks::recalculateAll('cron:daily');
});
add_hook('AdminClientProfileTabFields', 1, static function (array $vars): array {
    return Hooks::profileFields($vars);
});
add_hook('AdminClientProfileTabFieldsSave', 1, static function (array $vars): void {
    Hooks::profileSave($vars, $_REQUEST);
});
add_hook('AdminAreaClientSummaryPage', 1, static function (array $vars): string {
    return Hooks::clientSummary($vars);
});
add_hook('ClientAreaHomepagePanels', 1, static function (\WHMCS\View\Menu\Item $panels): void {
    Hooks::clientHomePanels($panels);
});
if (class_exists(\WHMCS\Module\AbstractWidget::class)) {
    add_hook('AdminHomeWidgets', 1, static function (): \AgencyDiscount\Admin\HomeWidget {
        return new \AgencyDiscount\Admin\HomeWidget();
    });
}
