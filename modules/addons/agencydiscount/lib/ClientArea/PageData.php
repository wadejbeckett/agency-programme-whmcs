<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\ClientArea;

use AgencyDiscount\Admin\Html;
use AgencyDiscount\Admin\Page;
use AgencyDiscount\AgencyStatus;
use AgencyDiscount\ClientNotFound;
use AgencyDiscount\Module;
use AgencyDiscount\Money;

/**
 * What the client area shows an agency: the Smarty variables of the module page
 * (templates/clientarea.tpl) and the body of the home panel. Everything is read-only and plain
 * text: amounts are pre-formatted, and the template escapes on output. A client that is not an
 * approved agency gets approved = false and nothing else.
 *
 * @phpstan-type Item array{domain:string, name:string, cycle:string, amount:string, monthly:string}
 */
final class PageData
{
    /** The module's client-area page. */
    public const MODULE_PAGE = 'index.php?m=agencydiscount';

    /** @return array<string, mixed> */
    public static function build(Module $module, ?int $clientId): array
    {
        $vars = ['approved' => false, 'text' => self::text()];
        if ($clientId === null || $clientId <= 0) {
            return $vars;
        }
        try {
            $status = AgencyStatus::of($module, $clientId);
        } catch (ClientNotFound) {
            return $vars;
        }
        if ($status === null) {
            return $vars;
        }

        $report = $status->report;
        $currency = $report->currency;
        $config = $module->config;
        $tier = $report->tier;
        $next = $report->nextTier;
        $shortfall = $status->shortfall();
        $invoice = $status->nextInvoice();
        $progress = $status->progressPercent();
        $year = (int) $module->clock->now()->format('Y');

        $tiers = [];
        foreach ($config->tiers->forCurrency($currency) as $t) {
            $tiers[] = [
                'index' => $t->index,
                'above' => Html::money($currency, $t->above),
                'percent' => Money::formatPercent($t->percent),
                'current' => $tier !== null && $t->index === $tier->index,
            ];
        }

        $services = array_map(static fn (array $s): array => self::item($currency, $s['domain'], $s['name'], $s['billingCycle'], $s['amount'], $s['monthly']), $report->services);
        $addons = array_map(static fn (array $a): array => self::item($currency, $a['domain'], $a['name'], $a['billingCycle'], $a['amount'], $a['monthly']), $report->addons);

        $text = $vars['text'];
        if ($invoice === null) {
            $nextInvoiceText = sprintf($text['next_invoice_none'], AgencyStatus::PREVIEW_DAYS);
        } elseif ($invoice['percent'] === null) {
            $nextInvoiceText = sprintf($text['next_invoice_no_discount'], $invoice['date'], Html::money($currency, $invoice['base']));
        } else {
            $nextInvoiceText = sprintf($text['next_invoice_line'], Html::money($currency, $invoice['discount']), $invoice['date'], Html::money($currency, $invoice['base']));
        }
        if (!$report->currencyHasTiers) {
            $toNextText = sprintf($text['no_tiers_currency'], $currency);
        } elseif ($next === null || $shortfall === null) {
            $toNextText = $text['top_tier'];
        } else {
            $toNextText = sprintf($text['to_next_line'], Html::money($currency, $shortfall), $next->index, Money::formatPercent($next->percent));
        }

        return array_merge($vars, [
            'approved' => true,
            'currency' => $currency,
            'spend' => Html::money($currency, $report->monthlySpend),
            'hasTiers' => $report->currencyHasTiers,
            'tier' => $tier === null ? null : ['index' => $tier->index, 'percent' => Money::formatPercent($tier->percent), 'label' => sprintf($text['tier_label'], $tier->index, Money::formatPercent($tier->percent))],
            'tiers' => $tiers,
            'hasProgress' => $progress !== null,
            'progress' => $progress ?? 0,
            'toNextText' => $toNextText,
            'nextInvoiceText' => $nextInvoiceText,
            'savedThisYear' => Html::money($currency, $module->repo->appliedDiscountTotal($clientId, $year . '-01-01')),
            'savedLabel' => sprintf($text['saved'], $year),
            'year' => $year,
            'noTiersText' => sprintf($text['no_tiers_currency'], $currency),
            'startsText' => $status->startsOn() === null ? '' : sprintf($text['starts_line'], (string) $status->startsOn()),
            'dryRun' => $config->dryRun,
            'counts' => [
                'groups' => array_values($module->repo->lookupNames('group', $config->productGroupIds)),
                'products' => array_values($module->repo->lookupNames('product', $config->productIds)),
                'addons' => array_values($module->repo->lookupNames('addon', $config->addonIds)),
            ],
            'notCountedText' => $text['not_counted_help'] . ($config->stackWithPromotions ? '' : ' ' . $text['not_counted_promotions']),
            'services' => $services,
            'addons' => $addons,
            'itemCount' => $report->countedItems(),
            'totals' => [
                'services' => Html::money($currency, Money::sum(...array_column($report->services, 'monthly'))),
                'addons' => Html::money($currency, Money::sum(...array_column($report->addons, 'monthly'))),
                'all' => Html::money($currency, $report->monthlySpend),
            ],
        ]);
    }

    /**
     * The home panel's body for an approved agency: tier, spend, next invoice, saved this year and
     * the amount to the next tier. '' when the vars are not an approved agency's.
     *
     * @param array<string, mixed> $vars from build()
     */
    public static function panelHtml(array $vars): string
    {
        if (($vars['approved'] ?? false) !== true) {
            return '';
        }
        $text = $vars['text'];
        $tier = $vars['tier'];
        $badge = $tier === null
            ? '<span class="badge badge-secondary">' . Html::e($vars['hasTiers'] ? $text['no_tier'] : sprintf($text['no_tiers_currency'], $vars['currency'])) . '</span>'
            : '<span class="badge badge-success">' . Html::e($tier['label']) . '</span>';
        $rows = [
            [$text['tier'], $badge],
            [$text['spend'], Html::e($vars['spend'])],
            [$text['next_invoice'], Html::e($vars['nextInvoiceText'])],
            [sprintf($text['saved'], $vars['year']), Html::e($vars['savedThisYear'])],
            [$text['to_next'], Html::e($vars['toNextText'])],
        ];
        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= '<div class="d-flex justify-content-between py-1"><span class="text-muted">' . Html::e($label) . '</span><span class="text-right">' . $value . '</span></div>';
        }
        if ($vars['startsText'] !== '') {
            $html .= '<p class="mb-0 small text-muted">' . Html::e($vars['startsText']) . '</p>';
        }
        if ($vars['dryRun']) {
            $html .= '<p class="mb-0 small text-muted">' . Html::e($text['dry_run']) . '</p>';
        }
        return $html;
    }

    /** @return Item */
    private static function item(string $currency, string $domain, string $name, string $cycle, string $amount, string $monthly): array
    {
        return ['domain' => $domain, 'name' => $name, 'cycle' => $cycle, 'amount' => Html::money($currency, $amount), 'monthly' => Html::money($currency, $monthly)];
    }

    /**
     * Every client-area string, keyed without the "ca_" prefix, so the template reads {$text.key}.
     *
     * @return array<string, string>
     */
    public static function text(): array
    {
        $text = [];
        foreach (Page::langKeys('ca_') as $key) {
            $text[substr($key, 3)] = Page::lang($key);
        }
        return $text;
    }
}
