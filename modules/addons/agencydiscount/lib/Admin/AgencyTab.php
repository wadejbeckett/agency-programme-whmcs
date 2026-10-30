<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\ClientNotFound;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\Repository;

/**
 * The agency detail view (Overview > Detail): everything about one approved agency, read-only.
 * A header panel with the tier, spend, progress, start state, approval and recalculation; the
 * counted services and add-ons; the upcoming invoices; the discount records with the year's total
 * (the same query as the client area's "Saved in <year>"), each record marked when it is left out
 * of that total; and links to the client's change log, profile tab and the Overview. WHMCS admin
 * markup only (panels, tables, labels); every dynamic string passes through Html::e().
 *
 * @phpstan-import-type ClientRecordRow from \AgencyDiscount\Repository
 */
final class AgencyTab
{
    public function __construct(private readonly Module $module)
    {
    }

    public function render(string $moduleLink, int $clientId): string
    {
        try {
            $status = AgencyStatus::of($this->module, $clientId);
        } catch (ClientNotFound) {
            $status = null;
        }
        if ($status === null) {
            return '<div class="alert alert-danger">' . Html::e(sprintf(Page::lang('agency_unknown'), $clientId)) . '</div>'
                . '<p><a href="' . Html::e($moduleLink) . '">' . Html::e(Page::lang('agency_back')) . '</a></p>';
        }
        return $this->header($status)
            . $this->services($status)
            . $this->addons($status)
            . $this->upcoming($status)
            . $this->records($status)
            . $this->footer($moduleLink, $status);
    }

    private function header(AgencyStatus $status): string
    {
        $report = $status->report;
        $agency = $status->agency;
        $currency = $report->currency;
        $approved = match (true) {
            $agency['approvedAt'] !== null => Html::e(sprintf(Page::lang('profile_approved_by'), $agency['approvedBy'] === '' ? 'admin' : $agency['approvedBy'], substr($agency['approvedAt'], 0, 10))),
            $agency['approvedBy'] !== '' => Html::e($agency['approvedBy']),
            default => '<span class="text-muted">' . Html::e(Page::lang('agency_approved_unknown')) . '</span>',
        };
        $rows = [
            [Page::lang('col_tier'), OverviewTab::tierBadge($status) . ' <span class="text-muted">' . Page::startsCell($status) . '</span>'],
            [Page::lang('col_spend'), Html::e(Html::money($currency, $report->monthlySpend)) . ' <span class="text-muted">(' . Html::e($report->countedItems() === 1 ? Page::lang('agency_counted_item') : sprintf(Page::lang('agency_counted_items'), $report->countedItems())) . ')</span>'],
            [Page::lang('col_to_next'), OverviewTab::progressCell($status)],
            [Page::lang('agency_approved'), $approved],
            [Page::lang('profile_note'), $agency['note'] === '' ? '<span class="text-muted">' . Html::e(Page::lang('agency_no_note')) . '</span>' : Html::e($agency['note'])],
            [Page::lang('agency_recalculated'), Html::e($agency['calculatedAt'] ?? Page::lang('never'))],
        ];
        $body = '';
        foreach ($rows as [$label, $value]) {
            $body .= '<tr><th class="agencydiscount-detail-label">' . Html::e($label) . '</th><td>' . $value . '</td></tr>';
        }
        return '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">'
            . '<a href="clientssummary.php?userid=' . $report->clientId . '">' . Html::e($report->clientName) . '</a>'
            . ' <small class="text-muted">' . Html::e($currency) . '</small></h3></div>'
            . '<table class="table table-condensed agencydiscount-detail"><tbody>' . $body . '</tbody></table></div>'
            . self::assets();
    }

    private function services(AgencyStatus $status): string
    {
        $report = $status->report;
        $currency = $report->currency;
        $html = '<h4>' . Html::e(Page::lang('agency_services')) . '</h4>';
        if ($report->services === []) {
            return $html . '<p class="text-muted">' . Html::e(Page::lang('agency_none')) . '</p>';
        }
        $rows = '';
        foreach ($report->services as $service) {
            $rows .= self::row([
                Html::e($service['domain']),
                Html::e($service['name']),
                Html::e($service['billingCycle']),
                Html::e(Html::money($currency, $service['amount'])),
                Html::e(Html::money($currency, $service['monthly'])),
                Html::e($service['nextInvoiceDate'] ?? Page::lang('agency_no_date')),
                Html::e(Page::lang($service['promoId'] !== 0 ? 'agency_yes' : 'agency_no')),
            ]);
        }
        $html .= self::table(['agency_col_domain', 'agency_col_product', 'agency_col_cycle', 'agency_col_amount', 'agency_col_monthly', 'agency_col_next_invoice', 'agency_col_promotion'], $rows);
        if (!$this->module->config->stackWithPromotions) {
            $html .= '<p class="text-muted">' . Html::e(Page::lang('agency_promotion_note')) . '</p>';
        }
        return $html;
    }

    private function addons(AgencyStatus $status): string
    {
        $report = $status->report;
        $currency = $report->currency;
        $html = '<h4>' . Html::e(Page::lang('agency_addons')) . '</h4>';
        if ($report->addons === []) {
            return $html . '<p class="text-muted">' . Html::e(Page::lang('agency_none')) . '</p>';
        }
        $rows = '';
        foreach ($report->addons as $addon) {
            $rows .= self::row([
                Html::e($addon['domain']),
                Html::e($addon['name']),
                Html::e($addon['billingCycle']),
                Html::e(Html::money($currency, $addon['amount'])),
                Html::e(Html::money($currency, $addon['monthly'])),
                Html::e($addon['nextInvoiceDate'] ?? Page::lang('agency_no_date')),
            ]);
        }
        return $html . self::table(['agency_col_domain', 'agency_col_addon', 'agency_col_cycle', 'agency_col_amount', 'agency_col_monthly', 'agency_col_next_invoice'], $rows);
    }

    private function upcoming(AgencyStatus $status): string
    {
        $currency = $status->report->currency;
        $html = '<h4>' . Html::e(sprintf(Page::lang('agency_upcoming'), AgencyStatus::PREVIEW_DAYS)) . '</h4>';
        if ($status->upcoming === []) {
            return $html . '<p class="text-muted">' . Html::e(sprintf(Page::lang('next_invoice_none'), AgencyStatus::PREVIEW_DAYS)) . '</p>';
        }
        $rows = '';
        foreach ($status->upcoming as $row) {
            $rows .= self::row([
                Html::e($row['date']),
                (string) $row['items'],
                Html::e(Html::money($currency, $row['base'])),
                match (true) {
                    $row['percent'] !== null => Html::e(Html::money($currency, $row['discount'])),
                    !$status->report->currencyHasTiers => '<span class="text-muted">' . Html::e(sprintf(Page::lang('no_tiers_for'), $currency)) . '</span>',
                    default => '<span class="text-muted">' . Html::e(Page::lang('agency_not_yet')) . '</span>',
                },
            ]);
        }
        return $html . self::table(['agency_col_date', 'agency_col_items', 'agency_col_base', 'agency_col_discount'], $rows)
            . '<p class="text-muted">' . Html::e(Page::lang('agency_upcoming_note')) . '</p>';
    }

    /**
     * The discount records newest first, each marked when it is left out of the year's total: a dry
     * run, a record without a discount line (an old placeholder), an invoice that is Cancelled or
     * Refunded or gone, a discount line removed by hand, or a record from before this year. The
     * total is the client area's query (applied, line present, invoice live, created on or after
     * 1 January), so the marks and the figure agree, a future-dated record included.
     */
    private function records(AgencyStatus $status): string
    {
        $report = $status->report;
        $currency = $report->currency;
        $year = (int) $this->module->clock->now()->format('Y');
        $total = $this->module->repo->appliedDiscountTotal($report->clientId, $year . '-01-01 00:00:00');
        $html = '<h4>' . Html::e(Page::lang('agency_records')) . '</h4>'
            . '<p>' . Html::e(sprintf(Page::lang('agency_saved_year'), $year, Html::money($currency, $total))) . ' <span class="text-muted">' . Html::e(Page::lang('agency_saved_year_note')) . '</span></p>';
        $records = $this->module->repo->clientInvoiceRecords($report->clientId);
        if ($records === []) {
            return $html . '<p class="text-muted">' . Html::e(Page::lang('agency_no_records')) . '</p>';
        }
        $rows = '';
        foreach ($records as $record) {
            $rows .= self::row([
                '<a href="invoices.php?action=edit&amp;id=' . $record['invoiceId'] . '">' . $record['invoiceId'] . '</a>' . ($record['line'] === 0 ? '' : ' <small class="text-muted">' . Html::e(sprintf(Page::lang('agency_line_n'), $record['line'] + 1)) . '</small>')
                    . ($record['lineInvoiceId'] === null || $record['lineInvoiceId'] === $record['invoiceId'] ? '' : ' <small class="text-muted">' . Html::e(sprintf(Page::lang('agency_line_moved'), $record['lineInvoiceId'])) . '</small>'),
                Html::e(substr($record['createdAt'], 0, 10)),
                (string) $record['tierIndex'],
                Html::e(Money::formatPercent($record['percent'])) . '%',
                Html::e(Html::money($currency, $record['base'])),
                Html::e(Html::money($currency, $record['amount'])),
                Html::e(Page::lang($record['taxed'] ? 'agency_yes' : 'agency_no')),
                self::recordState($record, $year),
            ]);
        }
        return $html . self::table(['agency_col_invoice', 'agency_col_date', 'agency_col_tier', 'agency_col_percent', 'agency_col_base', 'agency_col_discount', 'agency_col_taxed', 'agency_col_state'], $rows);
    }

    /**
     * Why a record is in or out of the year's total, mirroring Sql::APPLIED_DISCOUNT_TOTAL clause by
     * clause: "counted", or the first reason it is left out ("dry_run", "no_line", "line_removed",
     * "invoice_gone", "invoice_status" for an invoice outside Repository::SAVED_STATUSES, "earlier_year").
     * A line WHMCS moved to another invoice is judged by that invoice. Public so the sandbox test
     * can check it against the real query.
     *
     * @param ClientRecordRow $record
     */
    public static function state(array $record, int $year): string
    {
        return match (true) {
            $record['dryRun'] => 'dry_run',
            $record['itemId'] === null => 'no_line',
            $record['invoiceStatus'] === null => 'invoice_gone',
            !$record['lineOnInvoice'] => 'line_removed',
            !in_array($record['invoiceStatus'], Repository::SAVED_STATUSES, true) => 'invoice_status',
            (int) substr($record['createdAt'], 0, 4) < $year => 'earlier_year',
            default => 'counted',
        };
    }

    /** @param ClientRecordRow $record */
    private static function recordState(array $record, int $year): string
    {
        $state = self::state($record, $year);
        return match ($state) {
            'counted' => '<span class="label label-success">' . Html::e(Page::lang('agency_state_counted')) . '</span>',
            'dry_run' => '<span class="label label-warning">' . Html::e(Page::lang('agency_state_dry_run')) . '</span>',
            'invoice_status' => '<span class="label label-default">' . Html::e(sprintf(Page::lang('agency_state_invoice_status'), (string) $record['invoiceStatus'])) . '</span>',
            'earlier_year' => '<span class="text-muted">' . Html::e(sprintf(Page::lang('agency_state_earlier_year'), substr($record['createdAt'], 0, 4))) . '</span>',
            default => '<span class="label label-default">' . Html::e(Page::lang('agency_state_' . $state)) . '</span>',
        };
    }

    private function footer(string $moduleLink, AgencyStatus $status): string
    {
        $clientId = $status->report->clientId;
        return '<p class="agencydiscount-detail-links">'
            . '<a href="' . Html::link($moduleLink, ['action' => 'log', 'client' => $clientId]) . '">' . Html::e(Page::lang('agency_change_log')) . '</a>'
            . ' &middot; <a href="clientsprofile.php?userid=' . $clientId . '">' . Html::e(Page::lang('agency_profile')) . '</a>'
            . ' &middot; <a href="' . Html::e($moduleLink) . '">' . Html::e(Page::lang('agency_back')) . '</a></p>';
    }

    /** @param list<string> $cells already-escaped HTML */
    private static function row(array $cells): string
    {
        return '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
    }

    /** @param list<string> $headerKeys language keys */
    private static function table(array $headerKeys, string $rows): string
    {
        $head = '';
        foreach ($headerKeys as $key) {
            $head .= '<th>' . Html::e(Page::lang($key)) . '</th>';
        }
        return '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** The view's only styles: the header table's label column width and the progress bar the Overview also uses. */
    private static function assets(): string
    {
        return '<style>.agencydiscount-detail-label{width:14em;white-space:nowrap}.agencydiscount-detail td{vertical-align:middle}.agencydiscount-progress{height:8px;margin:0 0 3px;min-width:120px;max-width:320px}</style>';
    }
}
