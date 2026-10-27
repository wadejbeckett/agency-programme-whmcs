<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\ClientNotFound;
use AgencyDiscount\Module;

/**
 * The "Agency Programme" card on the admin client summary page (AdminAreaClientSummaryPage) and the
 * live "Right now" box on the profile tab: the same figures as the overview for one agency.
 */
final class ClientSummary
{
    /** The card, or '' when the client is not an approved agency or no longer exists in WHMCS. */
    public static function render(Module $module, int $clientId): string
    {
        $status = self::status($module, $clientId);
        if ($status === null) {
            return '';
        }
        $report = $status->report;
        $link = WidgetData::MODULE_LINK;

        $rows = self::rows($status)
            . '<tr><th>' . Html::e(Page::lang('summary_profile')) . '</th><td><a href="clientsprofile.php?userid=' . $clientId . '">' . Html::e(Page::lang('profile_approved')) . '</a></td></tr>';

        return '<div class="panel panel-default agencydiscount-summary"><div class="panel-heading"><h3 class="panel-title">' . Html::e(Page::lang('title')) . '</h3></div>'
            . '<div class="panel-body">' . self::notes($module, $status)
            . '<table class="table table-condensed agencydiscount-summary-table"><tbody>' . $rows . '</tbody></table>'
            . '<p><a href="' . Html::link($link, ['action' => 'agency', 'id' => $report->clientId]) . '">' . Html::e(Page::lang('summary_counted_items')) . '</a>'
            . ' &middot; <a href="' . Html::link($link, ['action' => 'log', 'client' => $report->clientId]) . '">' . Html::e(Page::lang('summary_change_log')) . '</a></p>'
            . '</div></div>' . self::assets();
    }

    /** The "Right now" box for the profile tab, or '' when there are no live figures. */
    public static function rightNow(Module $module, int $clientId): string
    {
        $status = self::status($module, $clientId);
        if ($status === null) {
            return '';
        }
        return '<div class="panel panel-default agencydiscount-right-now"><div class="panel-heading">' . Html::e(Page::lang('profile_right_now')) . '</div>'
            . '<div class="panel-body">' . self::notes($module, $status)
            . '<table class="table table-condensed"><tbody>' . self::rows($status) . '</tbody></table>'
            . '<a href="' . Html::link(WidgetData::MODULE_LINK, ['action' => 'agency', 'id' => $clientId]) . '">' . Html::e(Page::lang('summary_counted_items')) . '</a>'
            . '</div></div>' . self::assets();
    }

    private static function status(Module $module, int $clientId): ?AgencyStatus
    {
        try {
            return AgencyStatus::of($module, $clientId);
        } catch (ClientNotFound) {
            return null;
        }
    }

    /** Status, spend, next invoice and the progress to the next tier, as table rows. */
    private static function rows(AgencyStatus $status): string
    {
        $report = $status->report;
        $progress = OverviewTab::progressCell($status);
        return self::row(Page::lang('summary_status'), OverviewTab::tierBadge($status) . ($status->startsOn() === null ? '' : ' ' . Page::startsCell($status)))
            . self::row(Page::lang('summary_spend'), Html::e(Html::money($report->currency, $report->monthlySpend)))
            . self::row(Page::lang('summary_next_invoice'), Page::nextInvoiceCell($status))
            . ($progress === '&mdash;' ? '' : self::row(Page::lang('summary_to_next'), $progress));
    }

    private static function notes(Module $module, AgencyStatus $status): string
    {
        $html = '';
        if ($module->config->dryRun) {
            $html .= '<p class="text-warning">' . Html::e(Page::lang('summary_dry_run')) . '</p>';
        }
        $starts = $status->startsOn();
        if ($starts !== null) {
            $html .= '<p class="text-warning">' . Html::e(sprintf(Page::lang('summary_waiting'), $starts)) . '</p>';
        }
        return $html;
    }

    /**
     * The progress bar's slim style lives in the overview's style block, which is not on the client
     * pages; without it the bar is Bootstrap's 20px bar with a 20px gap under it.
     */
    private static function assets(): string
    {
        return '<style>.agencydiscount-summary .agencydiscount-progress,.agencydiscount-right-now .agencydiscount-progress{height:8px;margin:4px 0 3px;min-width:120px;max-width:360px}'
            . '.agencydiscount-summary-table>tbody>tr>th{width:240px}</style>';
    }

    /** @param string $value already-escaped HTML */
    private static function row(string $label, string $value): string
    {
        return '<tr><th>' . Html::e($label) . '</th><td>' . $value . '</td></tr>';
    }
}
