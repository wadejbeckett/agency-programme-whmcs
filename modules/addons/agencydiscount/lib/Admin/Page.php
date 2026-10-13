<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\ClientNotFound;
use AgencyDiscount\LogEntry;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\SpendReport;

/**
 * The addon's admin page (Addons > Agency Programme). Views: overview (default), agency, settings,
 * log, check. Every dynamic string passes through Html::e(); table cells are built as
 * already-escaped HTML.
 */
final class Page
{
    private const LOG_PAGE_SIZE = 50;

    /** @var array<string,string>|null */
    private static ?array $strings = null;

    /** Replaced when the Settings tab saves, so the rest of the page reflects the new configuration. */
    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * WHMCS HTML-encodes request input at init ("&" arrives as "&amp;"); the admin page decodes
     * every string in the POST once, recursively, before reading it. Idempotent for plain values.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public static function decodeInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::decodeInput($value);
            } elseif (is_string($value)) {
                $input[$key] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $input;
    }

    /** @param list<string> $problems */
    public static function configProblems(string $moduleLink, array $problems): string
    {
        $items = '';
        foreach ($problems as $problem) {
            $items .= '<li>' . Html::e($problem) . '</li>';
        }
        return '<div class="alert alert-danger"><p>The Agency Programme configuration has problems, so no discount is applied until they are fixed in System Settings &gt; Addon Modules:</p><ul>'
            . $items . '</ul></div>';
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param string $tokenField the ready-made hidden CSRF input from WHMCS; the caller has already validated the posted token
     */
    public function render(string $moduleLink, array $get, array $post, string $actor, string $tokenField): string
    {
        $view = self::text($get['action'] ?? null);
        $view = in_array($view, ['agency', 'settings', 'log', 'check'], true) ? $view : 'overview';

        // The Settings tab handles its POST first: a save or a mode switch changes what the banner says.
        $settings = '';
        if ($view === 'settings') {
            $tab = new SettingsTab($this->module, $tokenField);
            $settings = $tab->handle($post, $actor);
            $this->module = $tab->module;
        }

        $html = $this->nav($moduleLink, $view);
        if ($this->module->config->dryRun) {
            $html .= '<div class="alert alert-warning">' . Html::e(self::lang('dry_run_banner')) . '</div>';
        }

        return $html . match ($view) {
            'agency' => $this->agencyView($moduleLink, $get),
            'settings' => $settings,
            'log' => $this->logView($moduleLink, $get),
            'check' => $this->checkView(),
            default => $this->overviewView($moduleLink, $get, $post, $actor, $tokenField),
        };
    }

    private function nav(string $moduleLink, string $view): string
    {
        $tabs = [
            'overview' => [Html::e($moduleLink), self::lang('overview')],
            'settings' => [Html::link($moduleLink, ['action' => 'settings']), self::lang('settings')],
            'log' => [Html::link($moduleLink, ['action' => 'log']), self::lang('log')],
            'check' => [Html::link($moduleLink, ['action' => 'check']), self::lang('check')],
        ];
        $active = $view === 'agency' ? 'overview' : $view;
        $items = '';
        foreach ($tabs as $key => [$href, $label]) {
            $items .= '<li' . ($key === $active ? ' class="active"' : '') . '><a href="' . $href . '">' . Html::e($label) . '</a></li>';
        }
        return '<ul class="nav nav-tabs">' . $items . '</ul>';
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     */
    private function overviewView(string $moduleLink, array $get, array $post, string $actor, string $tokenField): string
    {
        return (new OverviewTab($this->module, $tokenField))->render($moduleLink, $get, $post, $actor);
    }

    /** @param array<string,mixed> $get */
    private function agencyView(string $moduleLink, array $get): string
    {
        $clientId = self::intParam($get['id'] ?? null) ?? 0;
        try {
            $status = AgencyStatus::of($this->module, $clientId);
        } catch (ClientNotFound) {
            $status = null;
        }
        if ($status === null) {
            return '<div class="alert alert-danger">Client ' . $clientId . ' is not an approved agency.</div>';
        }
        $report = $status->report;

        $currency = $report->currency;
        $html = '<h3>' . Html::e($report->clientName) . '</h3>'
            . '<p>' . Html::e($currency) . ' &middot; qualifying monthly spend ' . Html::e(Html::money($currency, $report->monthlySpend))
            . ' &middot; tier ' . self::tierCell($report)
            . ' &middot; ' . $report->countedItems() . ' counted items'
            . ' &middot; ' . self::startsCell($status) . '</p>';

        $html .= '<h4>Counted services</h4>';
        if ($report->services === []) {
            $html .= '<p>None.</p>';
        } else {
            $rows = '';
            foreach ($report->services as $service) {
                $rows .= self::row([
                    Html::e($service['domain']),
                    Html::e($service['name']),
                    Html::e($service['billingCycle']),
                    Html::e(Html::money($currency, $service['amount'])),
                    Html::e(Html::money($currency, $service['monthly'])),
                    Html::e($service['nextInvoiceDate'] ?? 'none'),
                    $service['promoId'] !== 0 ? 'yes' : 'no',
                ]);
            }
            $html .= self::table(['Domain', 'Product', 'Cycle', 'Amount', 'Monthly', 'Next invoice', 'Promotion'], $rows);
            if (!$this->module->config->stackWithPromotions) {
                $html .= '<p class="text-muted">Services on a promotion count towards the tier. Their invoice line is left out of the discount while the invoice also carries the promotion\'s own discount line.</p>';
            }
        }

        $html .= '<h4>Counted add-ons</h4>';
        if ($report->addons === []) {
            $html .= '<p>None.</p>';
        } else {
            $rows = '';
            foreach ($report->addons as $addon) {
                $rows .= self::row([
                    Html::e($addon['domain']),
                    Html::e($addon['name']),
                    Html::e($addon['billingCycle']),
                    Html::e(Html::money($currency, $addon['amount'])),
                    Html::e(Html::money($currency, $addon['monthly'])),
                    Html::e($addon['nextInvoiceDate'] ?? 'none'),
                ]);
            }
            $html .= self::table(['Domain', 'Add-on', 'Cycle', 'Amount', 'Monthly', 'Next invoice'], $rows);
        }

        $html .= '<h4>Upcoming invoices (next ' . AgencyStatus::PREVIEW_DAYS . ' days)</h4>';
        if ($status->upcoming === []) {
            $html .= '<p>None in ' . AgencyStatus::PREVIEW_DAYS . ' days.</p>';
        } else {
            $rows = '';
            foreach ($status->upcoming as $row) {
                $rows .= self::row([
                    Html::e($row['date']),
                    (string) $row['items'],
                    Html::e(Html::money($currency, $row['base'])),
                    Html::e(Html::money($currency, $row['discount'])),
                ]);
            }
            $html .= self::table(['Date', 'Items', 'Base', 'Discount'], $rows);
            $html .= '<p class="text-muted">An estimate from the next invoice dates: services on a promotion are included here, but the invoice itself is checked line by line.</p>';
        }

        return $html . '<p><a href="' . Html::link($moduleLink, ['action' => 'log', 'client' => $clientId]) . '">Change log for this client</a>'
            . ' &middot; <a href="' . Html::e($moduleLink) . '">Back to overview</a></p>';
    }

    /** @param array<string,mixed> $get */
    private function logView(string $moduleLink, array $get): string
    {
        $clientId = self::intParam($get['client'] ?? null);
        $page = max(1, self::intParam($get['page'] ?? null) ?? 1);
        $entries = $this->module->repo->logEntries($clientId, self::LOG_PAGE_SIZE + 1, ($page - 1) * self::LOG_PAGE_SIZE);
        $hasOlder = count($entries) > self::LOG_PAGE_SIZE;
        $entries = array_slice($entries, 0, self::LOG_PAGE_SIZE);

        $html = '<h3>Change log' . ($clientId === null ? '' : ' for client ' . $clientId) . '</h3>';
        if ($clientId !== null) {
            $html .= '<p><a href="' . Html::link($moduleLink, ['action' => 'log']) . '">All clients</a></p>';
        }
        if ($entries === []) {
            return $html . '<p>No log entries.</p>';
        }

        $clients = (new OverviewTab($this->module, ''))->clientsFor($entries);
        $rows = '';
        foreach ($entries as $entry) {
            $detail = json_encode($entry['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $rows .= self::row([
                Html::e($entry['createdAt']),
                $entry['clientId'] === null ? '' : '<a href="' . Html::link($moduleLink, ['action' => 'agency', 'id' => $entry['clientId']]) . '">' . $entry['clientId'] . '</a>',
                $entry['invoiceId'] === null ? '' : '<a href="invoices.php?action=edit&amp;id=' . $entry['invoiceId'] . '">' . $entry['invoiceId'] . '</a>',
                Html::e($entry['event']),
                Html::e($entry['actor']),
                Html::e(LogEntry::sentence($entry, $clients)),
                '<code>' . Html::e($detail === false ? '{}' : $detail) . '</code>',
            ]);
        }
        $html .= self::table(['Time', 'Client', 'Invoice', 'Event', 'Actor', self::lang('log_summary'), 'Detail'], $rows);

        $pager = '';
        if ($hasOlder) {
            $pager .= '<a class="btn btn-default" href="' . Html::link($moduleLink, ['action' => 'log', 'client' => $clientId, 'page' => $page + 1]) . '">Older</a> ';
        }
        if ($page > 1) {
            $pager .= '<a class="btn btn-default" href="' . Html::link($moduleLink, ['action' => 'log', 'client' => $clientId, 'page' => $page - 1]) . '">Newer</a>';
        }
        return $html . ($pager === '' ? '' : '<p>' . $pager . '</p>');
    }

    private function checkView(): string
    {
        $repo = $this->module->repo;
        $config = $this->module->config;

        $html = '<h3>Configuration check</h3>';
        $html .= $this->idTable('Product groups', 'group', $config->productGroupIds);
        $html .= $this->idTable('Products', 'product', $config->productIds);
        $html .= $this->idTable('Add-ons', 'addon', $config->addonIds);

        $html .= '<h4>Currencies</h4>';
        $rows = '';
        $known = $repo->currencyCodes();
        foreach ($known as $code) {
            $rows .= self::row([
                Html::e($code),
                $config->tiers->hasCurrency($code) ? 'tiers configured' : '<span class="text-danger">no tiers: agencies in this currency get no discount</span>',
            ]);
        }
        foreach ($config->tiers->currencies() as $code) {
            if (!in_array($code, $known, true)) {
                $rows .= self::row([Html::e($code), '<span class="text-danger">tiers configured, but WHMCS has no such currency</span>']);
            }
        }
        $html .= $rows === '' ? '<p>No currencies found.</p>' : self::table(['Currency', 'Status'], $rows);

        $html .= '<h4>Tiers</h4>';
        $currencies = $config->tiers->currencies();
        if ($currencies === []) {
            return $html . '<p><span class="text-danger">No tiers configured.</span></p>';
        }
        foreach ($currencies as $code) {
            $rows = '';
            foreach ($config->tiers->forCurrency($code) as $tier) {
                $rows .= self::row([
                    (string) $tier->index,
                    'above ' . Html::e(Html::money($code, $tier->above)) . ' a month',
                    Html::e(Money::formatPercent($tier->percent)) . '%',
                ]);
            }
            $html .= '<h5>' . Html::e($code) . '</h5>' . self::table(['Tier', 'Qualifying monthly spend', 'Discount'], $rows);
        }
        return $html;
    }

    /**
     * @param 'group'|'product'|'addon' $kind
     * @param list<int> $ids
     */
    private function idTable(string $title, string $kind, array $ids): string
    {
        $html = '<h4>' . Html::e($title) . '</h4>';
        if ($ids === []) {
            return $html . '<p>None configured.</p>';
        }
        $names = $this->module->repo->lookupNames($kind, $ids);
        $rows = '';
        foreach ($ids as $id) {
            $rows .= self::row([
                (string) $id,
                isset($names[$id]) ? Html::e($names[$id]) : '<span class="text-danger">not found</span>',
            ]);
        }
        return $html . self::table(['Id', 'Name'], $rows);
    }

    /** "2026-10-25: ZAR 42.50 off ZAR 850.00", "...: no discount on ...", or "none in 35 days". */
    public static function nextInvoiceCell(AgencyStatus $status): string
    {
        $first = $status->nextInvoice();
        if ($first === null) {
            return Html::e(sprintf(self::lang('next_invoice_none'), AgencyStatus::PREVIEW_DAYS));
        }
        $currency = $status->report->currency;
        $base = Html::money($currency, $first['base']);
        if ($first['percent'] === null) {
            return Html::e(sprintf(self::lang('next_invoice_no_discount'), $first['date'], $base));
        }
        return Html::e(sprintf(self::lang('next_invoice'), $first['date'], Html::money($currency, $first['discount']), $base));
    }

    /** "active", "active since <date>" (a start date already reached) or a flagged "starts <date>". */
    public static function startsCell(AgencyStatus $status): string
    {
        $starts = $status->startsOn();
        if ($starts !== null) {
            return '<span class="text-warning">' . Html::e(sprintf(self::lang('starts'), $starts)) . '</span>';
        }
        $from = $status->agency['effectiveFrom'];
        return Html::e($from === null ? self::lang('active') : sprintf(self::lang('active_since'), substr($from, 0, 10)));
    }

    /** "2 (10%)", "none", or a warning that the report's currency has no tiers. */
    public static function tierCell(SpendReport $report): string
    {
        if (!$report->currencyHasTiers) {
            return '<span class="text-danger">no tiers for ' . Html::e($report->currency) . '</span>';
        }
        if ($report->tier === null) {
            return 'none';
        }
        return $report->tier->index . ' (' . Html::e(Money::formatPercent($report->tier->percent)) . '%)';
    }

    /** @param list<string> $cells already-escaped HTML */
    private static function row(array $cells): string
    {
        return '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
    }

    /** @param list<string> $headers plain text */
    private static function table(array $headers, string $rows, string $attributes = ''): string
    {
        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . Html::e($header) . '</th>';
        }
        return '<table class="table table-striped"' . $attributes . '><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function intParam(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 ? (int) $value : null;
    }

    /** Text from the English language file (the addon ships English only for now). */
    public static function lang(string $key): string
    {
        if (self::$strings === null) {
            /** @var array<string, mixed> $_ADDONLANG filled by the language file */
            $_ADDONLANG = [];
            include dirname(__DIR__, 2) . '/lang/english.php';
            self::$strings = [];
            foreach ($_ADDONLANG as $name => $text) {
                if (is_string($text)) {
                    self::$strings[(string) $name] = $text;
                }
            }
        }
        return self::$strings[$key] ?? $key;
    }
}
