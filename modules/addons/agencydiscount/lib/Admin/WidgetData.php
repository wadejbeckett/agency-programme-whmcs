<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\Module;
use AgencyDiscount\Money;

/**
 * The numbers behind the admin home widget and their rendering, kept apart from HomeWidget so they
 * can be built on ArrayRepository and tested; HomeWidget only wraps them in WHMCS's widget class.
 *
 * @phpstan-type WidgetRow array{clientId:int, name:string, currency:string, spend:string, tier:?array{index:int, percent:string}, hasTiers:bool, nextInvoice:?array{date:string, base:string, discount:string}, startsOn:?string}
 * @phpstan-type Data array{agencies:int, atTier:int, waiting:int, spend:array<string,string>, discount:array<string,string>, dryRun:bool, lastRecalculation:?string, missing:list<int>, rows:list<WidgetRow>, days:int}
 */
final class WidgetData
{
    public const MODULE_LINK = 'addonmodules.php?module=agencydiscount';

    /** @return Data */
    public static function build(Module $module): array
    {
        $missing = [];
        $statuses = AgencyStatus::all($module, $missing);
        $data = [
            'agencies' => count($statuses),
            'atTier' => 0,
            'waiting' => 0,
            'spend' => [],
            'discount' => [],
            'dryRun' => $module->config->dryRun,
            'lastRecalculation' => null,
            'missing' => $missing,
            'rows' => [],
            'days' => AgencyStatus::PREVIEW_DAYS,
        ];
        foreach ($statuses as $status) {
            $report = $status->report;
            $currency = $report->currency;
            $data['atTier'] += $report->tier === null ? 0 : 1;
            $data['waiting'] += $status->isWaiting() ? 1 : 0;
            $data['spend'][$currency] = Money::sum($data['spend'][$currency] ?? '0.00', $report->monthlySpend);
            $data['discount'][$currency] = Money::sum($data['discount'][$currency] ?? '0.00', $status->discountInWindow());
            $calculatedAt = $status->agency['calculatedAt'];
            if ($calculatedAt !== null && ($data['lastRecalculation'] === null || $calculatedAt > $data['lastRecalculation'])) {
                $data['lastRecalculation'] = $calculatedAt;
            }
            $next = $status->nextInvoice();
            $data['rows'][] = [
                'clientId' => $report->clientId,
                'name' => $report->clientName,
                'currency' => $currency,
                'spend' => $report->monthlySpend,
                'tier' => $report->tier === null ? null : ['index' => $report->tier->index, 'percent' => $report->tier->percent],
                'hasTiers' => $report->currencyHasTiers,
                'nextInvoice' => $next === null ? null : ['date' => $next['date'], 'base' => $next['base'], 'discount' => $next['discount']],
                'startsOn' => $status->startsOn(),
            ];
        }
        ksort($data['spend']);
        ksort($data['discount']);
        return $data;
    }

    /** @param Data $data */
    public static function render(array $data): string
    {
        $html = '<div class="widget-content-padded agencydiscount-widget">';
        foreach ($data['missing'] as $clientId) {
            $html .= '<div class="alert alert-warning">' . Html::e(sprintf(Page::lang('client_missing'), $clientId)) . '</div>';
        }

        $mode = '<span class="label label-' . ($data['dryRun'] ? 'warning' : 'success') . '">' . Html::e(Page::lang($data['dryRun'] ? 'mode_dry_run' : 'mode_live')) . '</span>';
        $html .= '<div class="row">'
            . self::stat('<div class="agencydiscount-widget-value">' . $data['agencies'] . '</div>', Page::lang('widget_agencies'))
            . self::stat(self::perCurrency($data['spend']), Page::lang('widget_spend'))
            . self::stat(self::perCurrency($data['discount']), sprintf(Page::lang('widget_discount'), $data['days']))
            . self::stat('<div class="agencydiscount-widget-value">' . $mode . '</div>', Page::lang('tile_mode'))
            . '</div>';

        if ($data['rows'] === []) {
            $html .= '<p class="text-muted">' . Html::e(Page::lang('widget_none')) . '</p>';
        } else {
            $rows = '';
            foreach ($data['rows'] as $row) {
                $rows .= '<tr><td><a href="clientssummary.php?userid=' . $row['clientId'] . '">' . Html::e($row['name']) . '</a>'
                    . ($row['startsOn'] === null ? '' : ' <span class="text-warning">' . Html::e(sprintf(Page::lang('starts'), $row['startsOn'])) . '</span>') . '</td>'
                    . '<td class="text-right">' . Html::e(Html::money($row['currency'], $row['spend'])) . '</td>'
                    . '<td>' . self::badge($row) . '</td>'
                    . '<td class="text-right">' . Html::e(self::nextInvoice($row)) . '</td></tr>';
            }
            $html .= '<div class="table-responsive"><table class="table table-condensed agencydiscount-widget-table"><thead><tr>'
                . '<th>' . Html::e(Page::lang('col_client')) . '</th><th class="text-right">' . Html::e(Page::lang('col_spend')) . '</th><th>' . Html::e(Page::lang('col_tier')) . '</th><th class="text-right">' . Html::e(Page::lang('col_next_invoice')) . '</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }

        return $html . '<p class="text-muted small">' . Html::e(Page::lang('widget_last_check')) . ': ' . Html::e($data['lastRecalculation'] ?? Page::lang('never'))
            . ' &middot; <a href="' . Html::e(self::MODULE_LINK) . '">' . Html::e(Page::lang('widget_open')) . '</a></p>'
            . '</div>' . self::assets();
    }

    /** @param string $value already-escaped HTML */
    private static function stat(string $value, string $label): string
    {
        return '<div class="col-xs-6 col-sm-3 agencydiscount-widget-stat">' . $value . '<div class="agencydiscount-widget-label">' . Html::e($label) . '</div></div>';
    }

    /** @param array<string,string> $amounts currency => amount, sorted by the builder */
    private static function perCurrency(array $amounts): string
    {
        if ($amounts === []) {
            return '<div class="agencydiscount-widget-value">&mdash;</div>';
        }
        $html = '';
        foreach ($amounts as $currency => $amount) {
            $html .= '<div class="agencydiscount-widget-value">' . Html::e(Html::money($currency, $amount)) . '</div>';
        }
        return $html;
    }

    /** @param WidgetRow $row */
    private static function badge(array $row): string
    {
        if (!$row['hasTiers']) {
            return '<span class="label label-danger">' . Html::e(sprintf(Page::lang('no_tiers_for'), $row['currency'])) . '</span>';
        }
        if ($row['tier'] === null) {
            return '<span class="label label-default">' . Html::e(Page::lang('no_tier')) . '</span>';
        }
        return '<span class="label label-success">' . Html::e(sprintf(Page::lang('tier_badge'), $row['tier']['index'], Money::formatPercent($row['tier']['percent']))) . '</span>';
    }

    /** @param WidgetRow $row */
    private static function nextInvoice(array $row): string
    {
        $next = $row['nextInvoice'];
        if ($next === null) {
            return sprintf(Page::lang('next_invoice_none'), AgencyStatus::PREVIEW_DAYS);
        }
        return $next['date'] . ': ' . Html::money($row['currency'], $next['discount']);
    }

    private static function assets(): string
    {
        return '<style>.agencydiscount-widget-value{font-size:18px;font-weight:bold;line-height:1.3}.agencydiscount-widget-label{color:#777;font-size:12px}.agencydiscount-widget-stat{margin-bottom:10px}.agencydiscount-widget-table{margin-bottom:8px}</style>';
    }
}
