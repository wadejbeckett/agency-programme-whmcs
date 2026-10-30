<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\ClientNotFound;
use AgencyDiscount\Module;

/**
 * The "Agency Programme" card on the admin client summary page (AdminAreaClientSummaryPage): the
 * tier badge with the start state, the qualifying monthly spend, a dry-run note while dry-run is
 * on, and one link to the agency detail page, which carries everything else (next invoice,
 * progress, records, change log). The approval itself is on the client's profile tab.
 */
final class ClientSummary
{
    /** The card, or '' when the client is not an approved agency or no longer exists in WHMCS. */
    public static function render(Module $module, int $clientId): string
    {
        try {
            $status = AgencyStatus::of($module, $clientId);
        } catch (ClientNotFound) {
            return '';
        }
        if ($status === null) {
            return '';
        }
        $report = $status->report;
        $notes = '';
        if ($module->config->dryRun) {
            $notes .= '<p class="text-warning">' . Html::e(Page::lang('summary_dry_run')) . '</p>';
        }
        $starts = $status->startsOn();
        if ($starts !== null) {
            $notes .= '<p class="text-warning">' . Html::e(sprintf(Page::lang('summary_waiting'), $starts)) . '</p>';
        }
        return '<div class="panel panel-default agencydiscount-summary"><div class="panel-heading"><h3 class="panel-title">' . Html::e(Page::lang('title')) . '</h3></div>'
            . '<div class="panel-body">' . $notes
            . '<table class="table table-condensed"><tbody>'
            . '<tr><th>' . Html::e(Page::lang('summary_status')) . '</th><td>' . OverviewTab::tierBadge($status) . ($starts === null ? '' : ' ' . Page::startsCell($status)) . '</td></tr>'
            . '<tr><th>' . Html::e(Page::lang('summary_spend')) . '</th><td>' . Html::e(Html::money($report->currency, $report->monthlySpend)) . '</td></tr>'
            . '</tbody></table>'
            . '<p><a href="' . Html::link(WidgetData::MODULE_LINK, ['action' => 'agency', 'id' => $report->clientId]) . '">' . Html::e(Page::lang('summary_detail')) . '</a></p>'
            . '</div></div>';
    }
}
