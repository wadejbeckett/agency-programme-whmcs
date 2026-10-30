<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\ActivityLog;
use AgencyDiscount\AgencyStatus;
use AgencyDiscount\Clock;
use AgencyDiscount\Config;
use AgencyDiscount\ConfigException;
use AgencyDiscount\LogEntry;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\Repository;
use AgencyDiscount\SpendReport;

/**
 * The addon's admin page (Addons > Agency Programme). Views: overview (default), agency, settings,
 * log; any other action (an old "check" link, say) is the overview. Every dynamic string passes
 * through Html::e(); table cells are built as already-escaped HTML.
 *
 * When the stored settings do not parse (Page::forStoredSettings), the page is the repair page:
 * a banner listing the problems on every view, the Settings tab filled from the stored values so
 * they can be fixed, the Change log tab (it needs only the repository), and nothing else, since
 * the Overview and the agency view are computed from a valid Config.
 * A Settings save that validates repairs the configuration and the same request renders the normal page.
 */
final class Page
{
    private const LOG_PAGE_SIZE = 50;

    /** @var array<string,string>|null */
    private static ?array $strings = null;

    /** The module on a valid configuration, replaced when the Settings tab saves; null on the repair page. */
    private ?Module $module;

    /**
     * @param list<string> $problems the problems with the stored settings; empty when $module is set
     */
    private function __construct(
        private readonly Repository $repo,
        private readonly Clock $clock,
        private readonly ActivityLog $activity,
        ?Module $module,
        private array $problems,
    ) {
        $this->module = $module;
    }

    public static function of(Module $module): self
    {
        return new self($module->repo, $module->clock, $module->activity, $module, []);
    }

    /** The page for the stored settings: the normal page when they parse, otherwise the repair page. */
    public static function forStoredSettings(Repository $repo, Clock $clock, ActivityLog $activity): self
    {
        try {
            return self::of(new Module($repo, Config::fromSettings($repo->settings()), $clock, $activity));
        } catch (ConfigException $e) {
            return new self($repo, $clock, $activity, null, $e->problems());
        }
    }

    /** The module on a valid configuration; the views that need one are only reached when it is set. */
    private function module(): Module
    {
        return $this->module ?? throw new \LogicException('no valid configuration');
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

    /**
     * The repair page's banner: the problems with the stored settings and where to fix them, the
     * Settings tab (linked) or, on that tab, the form below.
     *
     * @param list<string> $problems
     */
    public static function configProblems(string $moduleLink, array $problems, bool $onSettings = false): string
    {
        $items = '';
        foreach ($problems as $problem) {
            $items .= '<li>' . Html::e($problem) . '</li>';
        }
        $lead = $onSettings
            ? Html::e(self::lang('config_problems_here'))
            : Html::e(self::lang('config_problems')) . ' <a href="' . Html::link($moduleLink, ['action' => 'settings']) . '">' . Html::e(self::lang('config_problems_link')) . '</a>.';
        return '<div class="alert alert-danger"><p>' . $lead . '</p><ul>' . $items . '</ul></div>';
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param string $tokenField the ready-made hidden CSRF input from WHMCS; the caller has already validated the posted token
     */
    public function render(string $moduleLink, array $get, array $post, string $actor, string $tokenField): string
    {
        $view = self::text($get['action'] ?? null);
        $view = in_array($view, ['agency', 'settings', 'log'], true) ? $view : 'overview';

        // The Settings tab handles its POST first: a save or a mode switch changes what the banner says, and a save can repair the configuration.
        $settings = '';
        if ($view === 'settings') {
            $tab = $this->module === null
                ? SettingsTab::repair($this->repo, $this->clock, $this->activity, $this->problems, $tokenField)
                : SettingsTab::of($this->module, $tokenField);
            $settings = $tab->handle($post, $actor);
            $this->module = $tab->module;
            if ($this->module !== null) {
                $this->problems = [];
            }
        }

        $html = $this->nav($moduleLink, $view);
        if ($this->module === null) {
            $html .= self::configProblems($moduleLink, $this->problems, $view === 'settings');
            return $html . match ($view) {
                'settings' => $settings,
                'log' => $this->logView($moduleLink, $get),
                default => '',
            };
        }
        if ($this->module->config->dryRun) {
            $html .= '<div class="alert alert-warning">' . Html::e(self::lang('dry_run_banner')) . '</div>';
        }

        return $html . match ($view) {
            'agency' => $this->agencyView($moduleLink, $get),
            'settings' => $settings,
            'log' => $this->logView($moduleLink, $get),
            default => $this->overviewView($moduleLink, $get, $post, $actor, $tokenField),
        };
    }

    private function nav(string $moduleLink, string $view): string
    {
        $tabs = [
            'overview' => [Html::e($moduleLink), self::lang('overview')],
            'settings' => [Html::link($moduleLink, ['action' => 'settings']), self::lang('settings')],
            'log' => [Html::link($moduleLink, ['action' => 'log']), self::lang('log')],
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
        return (new OverviewTab($this->module(), $tokenField))->render($moduleLink, $get, $post, $actor);
    }

    /** @param array<string,mixed> $get */
    private function agencyView(string $moduleLink, array $get): string
    {
        return (new AgencyTab($this->module()))->render($moduleLink, self::intParam($get['id'] ?? null) ?? 0);
    }

    /** @param array<string,mixed> $get */
    private function logView(string $moduleLink, array $get): string
    {
        $clientId = self::intParam($get['client'] ?? null);
        $page = max(1, self::intParam($get['page'] ?? null) ?? 1);
        $entries = $this->repo->logEntries($clientId, self::LOG_PAGE_SIZE + 1, ($page - 1) * self::LOG_PAGE_SIZE);
        $hasOlder = count($entries) > self::LOG_PAGE_SIZE;
        $entries = array_slice($entries, 0, self::LOG_PAGE_SIZE);

        $html = '<h3>Change log' . ($clientId === null ? '' : ' for client ' . $clientId) . '</h3>';
        if ($clientId !== null) {
            $html .= '<p><a href="' . Html::link($moduleLink, ['action' => 'log']) . '">All clients</a></p>';
        }
        if ($entries === []) {
            return $html . '<p>No log entries.</p>';
        }

        $clients = OverviewTab::clientsFor($this->repo, $entries);
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

    /** "2026-10-25: ABC 42.50 off ABC 850.00", "...: no discount on ...", or "none in 35 days". */
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

    /**
     * The language keys starting with $prefix, in file order.
     *
     * @return list<string>
     */
    public static function langKeys(string $prefix): array
    {
        self::lang('title');
        return array_values(array_filter(array_keys(self::$strings ?? []), static fn (string $key): bool => str_starts_with($key, $prefix)));
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
