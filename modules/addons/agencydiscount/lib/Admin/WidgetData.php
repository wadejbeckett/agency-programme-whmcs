<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\Repository;

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
    /** The tbladdonmodules setting WHMCS keeps the addon's access list in: comma-separated admin role ids. */
    public const ACCESS_SETTING = 'access';

    /**
     * Whether the admin may see the programme's figures: WHMCS's own access list for this addon (the
     * roles ticked under Addon Modules > Configure) must name the admin's role. No admin, no role or
     * no access list means no.
     */
    public static function roleAllowed(Repository $repo, ?int $adminId): bool
    {
        if ($adminId === null || $adminId <= 0) {
            return false;
        }
        $roleId = $repo->adminRoleId($adminId);
        if ($roleId === null) {
            return false;
        }
        $allowed = [];
        foreach (explode(',', $repo->settings()[self::ACCESS_SETTING] ?? '') as $id) {
            if (ctype_digit(trim($id))) {
                $allowed[] = (int) trim($id);
            }
        }
        return in_array($roleId, $allowed, true);
    }

    /**
     * @return Data|array{denied:true} denied when the admin's role is not on the addon's access list
     */
    public static function build(Module $module, ?int $adminId): array
    {
        if (!self::roleAllowed($module->repo, $adminId)) {
            return ['denied' => true];
        }
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

    /**
     * The widget body, in the markup WHMCS's own widgets use (modules/widgets/, shipped unencoded as
     * the reference), so the admin theme styles it like the rest of the dashboard: the four figures
     * as an `icon-stats` block (the Support widget's pattern, styled by both admin themes without a
     * widget-specific rule), the agency list inside `widget-content-padded` (the theme's scrolling
     * list box) and a footer line of links. One column wide, so each agency takes two short lines:
     * name and spend, then tier and the next discount.
     *
     * @param Data|array{denied:true} $data
     */
    public static function render(array $data): string
    {
        if (isset($data['denied'])) {
            return '<div class="widget-content-padded text-muted">' . Html::e(Page::lang('widget_denied')) . '</div>';
        }
        $html = '';
        foreach ($data['missing'] as $clientId) {
            $html .= '<div class="alert alert-warning agencydiscount-widget-alert">' . Html::e(sprintf(Page::lang('client_missing'), $clientId)) . '</div>';
        }

        $agencies = (string) $data['agencies'];
        if ($data['agencies'] > 0) {
            $agencies .= ' <span class="unit">' . Html::e(sprintf(Page::lang('widget_agencies_at_tier'), $data['atTier'])) . '</span>';
        }
        $mode = '<span class="label label-' . ($data['dryRun'] ? 'warning' : 'success') . '">' . Html::e(Page::lang($data['dryRun'] ? 'mode_dry_run' : 'mode_live')) . '</span>';
        // With one currency its code goes on the note, so the amount keeps the narrow figure line.
        $currency = count($data['spend']) === 1 ? ', ' . array_key_first($data['spend']) : '';
        $html .= '<div class="icon-stats agencydiscount-widget"><div class="row">'
            . self::stat('pe-7s-cash', 'color-green', Page::lang('widget_spend') . $currency, self::perCurrency($data['spend']))
            . self::stat('pe-7s-piggy', 'color-orange', sprintf(Page::lang('widget_discount'), $data['days']) . $currency, self::perCurrency($data['discount']))
            . self::stat('pe-7s-users', 'color-blue', Page::lang('widget_agencies'), '<div class="number">' . $agencies . '</div>')
            . self::stat($data['dryRun'] ? 'pe-7s-clock' : 'pe-7s-check', $data['dryRun'] ? 'color-orange' : 'color-green', Page::lang('tile_mode'), '<div class="number">' . $mode . '</div>')
            . '</div></div>';

        if ($data['rows'] === []) {
            $html .= '<div class="widget-content-padded text-center text-muted">' . Html::e(Page::lang('widget_none')) . '</div>';
        } else {
            $rows = '';
            foreach ($data['rows'] as $row) {
                $rows .= '<tr><td class="agencydiscount-widget-name">'
                    . '<a href="clientssummary.php?userid=' . $row['clientId'] . '" title="' . Html::e($row['name']) . '">' . Html::e($row['name']) . '</a>'
                    . '<div class="agencydiscount-widget-sub">' . self::badge($row) . '</div></td>'
                    . '<td class="text-right agencydiscount-widget-figures">' . Html::e(Html::money($row['currency'], $row['spend']))
                    . '<div class="agencydiscount-widget-sub">' . self::nextInvoice($row) . '</div></td></tr>';
            }
            $html .= '<div class="widget-content-padded agencydiscount-widget-list"><table class="table table-condensed agencydiscount-widget-table"><thead><tr>'
                . '<th scope="col">' . Html::e(Page::lang('widget_col_agency')) . '</th>'
                . '<th scope="col" class="text-right">' . Html::e(Page::lang('widget_col_figures')) . '</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }

        return $html . '<div class="footer agencydiscount-widget-footer"><span>' . Html::e(Page::lang('widget_last_check')) . ': ' . Html::e($data['lastRecalculation'] ?? Page::lang('never')) . '</span>'
            . '<a href="' . Html::e(self::MODULE_LINK) . '">' . Html::e(Page::lang('widget_open')) . '</a></div>'
            . self::assets();
    }

    /** The one line shown when the figures could not be built; the cause is in the activity log. */
    public static function unavailable(): string
    {
        return '<div class="widget-content-padded">' . Html::e(Page::lang('widget_unavailable')) . '</div>';
    }

    /**
     * One `icon-stats` item, as the Support widget builds them: a linked icon, the note, the figure.
     *
     * @param string $figure already-escaped HTML, one or more `<div class="number">` lines
     */
    private static function stat(string $icon, string $colour, string $note, string $figure): string
    {
        return '<div class="col-sm-6"><div class="item">'
            . '<div class="icon-holder text-center ' . $colour . '"><a href="' . Html::e(self::MODULE_LINK) . '"><i class="' . $icon . '" aria-hidden="true"></i></a></div>'
            . '<div class="data"><div class="note">' . Html::e($note) . '</div>' . $figure . '</div>'
            . '</div></div>';
    }

    /**
     * One number line per currency; the currency code is on the line only when there is more than
     * one (with one, it is on the note).
     *
     * @param array<string,string> $amounts currency => amount, sorted by the builder
     */
    private static function perCurrency(array $amounts): string
    {
        if ($amounts === []) {
            return '<div class="number">&mdash;</div>';
        }
        $html = '';
        foreach ($amounts as $currency => $amount) {
            $html .= '<div class="number">' . Html::e(Html::money('', $amount))
                . (count($amounts) > 1 ? ' <span class="unit">' . Html::e($currency) . '</span>' : '') . '</div>';
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

    /**
     * The second line of the figures column, as escaped HTML: the next invoice's discount, or the
     * start date while an agency's discount has not started and its next invoice gets none.
     *
     * @param WidgetRow $row
     */
    private static function nextInvoice(array $row): string
    {
        $next = $row['nextInvoice'];
        $discounted = $next !== null && Money::isPositive($next['discount']);
        if ($row['startsOn'] !== null && !$discounted) {
            return '<span class="text-warning">' . Html::e(sprintf(Page::lang('starts'), $row['startsOn'])) . '</span>';
        }
        if ($next === null) {
            return Html::e(sprintf(Page::lang('next_invoice_none'), AgencyStatus::PREVIEW_DAYS));
        }
        if (!$discounted) {
            return Html::e(sprintf(Page::lang('widget_next_no_discount'), $next['date']));
        }
        // The currency is on the spend line just above; the amount goes without it so the agency's
        // name keeps as much of the narrow widget as possible.
        return Html::e(sprintf(Page::lang('widget_next_discount'), Html::money('', $next['discount']), $next['date']));
    }

    /**
     * Only what the themes do not style for a third-party widget: the footer (both themes style
     * `.footer` under `.widget-support` only; these are its values), the two-line rows and the
     * alerts above the stats. Theme rules are never overridden.
     */
    private static function assets(): string
    {
        return '<style>'
            . '.agencydiscount-widget-alert{margin:12px 12px 0}'
            . '.agencydiscount-widget-list .table{margin-bottom:0}'
            . '.agencydiscount-widget-table>thead>tr>th{border-top:0;font-size:12px;white-space:nowrap}'
            . '.agencydiscount-widget-table>tbody>tr>td{vertical-align:top}'
            . '.agencydiscount-widget-name{width:100%;max-width:0}'
            . '.agencydiscount-widget-name>a{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '.agencydiscount-widget-figures{white-space:nowrap}'
            . '.agencydiscount-widget-sub{margin-top:2px;font-size:12px;line-height:20px;color:var(--text-subtle,#999)}'
            . '.agencydiscount-widget-footer{margin:4px 10px 0;padding:8px 12px 3px;border-top:1px solid var(--border-subtle,#eee);text-align:center;font-size:12px;color:var(--text-subtle,#999)}'
            . '.agencydiscount-widget-footer span,.agencydiscount-widget-footer a{display:inline-block;padding:0 6px;white-space:nowrap}'
            . '</style>';
    }
}
