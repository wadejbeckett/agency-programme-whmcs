<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\LogEntry;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\Repository;

/**
 * The Overview tab: four tiles, the agencies table with filters and flags, and a side column with
 * what needs attention and the most recent change-log entries. Viewing it changes nothing; only
 * the explicit "Recalculate now" POST writes.
 *
 * GET parameters: filter=all|tier|waiting|no_tiers and q=<part of a client name>.
 */
final class OverviewTab
{
    public const ACTION_RECALCULATE = 'recalculate';
    public const FILTERS = ['all', 'tier', 'waiting', 'no_tiers'];
    /** How many change-log entries the side column shows. */
    public const RECENT_ENTRIES = 6;
    /** How many newest log entries are scanned for the last mode switch. */
    private const MODE_SCAN = 200;

    public function __construct(private readonly Module $module, private readonly string $tokenField)
    {
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     */
    public function render(string $moduleLink, array $get, array $post, string $actor): string
    {
        $html = '';
        if (($post['action'] ?? null) === self::ACTION_RECALCULATE) {
            $reports = $this->module->recalculator()->recalculateAll($actor);
            $html .= '<div class="alert alert-success">' . Html::e(sprintf(Page::lang('recalculated'), count($reports))) . '</div>';
        }

        $missing = [];
        $statuses = AgencyStatus::all($this->module, $missing);
        foreach ($missing as $clientId) {
            $html .= '<div class="alert alert-warning">' . Html::e(sprintf(Page::lang('client_missing'), $clientId)) . '</div>';
        }

        return $html . $this->tiles($moduleLink, $statuses)
            . '<div class="row"><div class="col-md-9">' . $this->agencies($moduleLink, $get, $statuses) . '</div>'
            . '<div class="col-md-3">' . $this->attention($moduleLink, $statuses) . $this->recent($moduleLink, $statuses) . '</div></div>'
            . self::assets();
    }

    /** @param list<AgencyStatus> $statuses */
    private function tiles(string $moduleLink, array $statuses): string
    {
        $atTier = 0;
        $waiting = 0;
        $spend = [];
        $discount = [];
        foreach ($statuses as $status) {
            $atTier += $status->report->tier === null ? 0 : 1;
            $waiting += $status->isWaiting() ? 1 : 0;
            $currency = $status->report->currency;
            $spend[$currency] = Money::sum($spend[$currency] ?? '0.00', $status->report->monthlySpend);
            $discount[$currency] = Money::sum($discount[$currency] ?? '0.00', $status->discountInWindow());
        }
        $dryRun = $this->module->config->dryRun;
        $mode = '<span class="label label-' . ($dryRun ? 'warning' : 'success') . '">' . Html::e(Page::lang($dryRun ? 'mode_dry_run' : 'mode_live')) . '</span>';

        return '<div class="row agencydiscount-tiles">'
            . self::tile('<div class="agencydiscount-tile-value">' . count($statuses) . '</div>', Page::lang('tile_agencies'), Html::e(sprintf(Page::lang('tile_agencies_detail'), $atTier, $waiting)))
            . self::tile(self::perCurrency($spend), Page::lang('tile_spend'), '')
            . self::tile(self::perCurrency($discount), sprintf(Page::lang('tile_discount'), AgencyStatus::PREVIEW_DAYS), Html::e(Page::lang($dryRun ? 'tile_discount_dry_run' : 'tile_discount_live')))
            . self::tile('<div class="agencydiscount-tile-value">' . $mode . '</div>', Page::lang('tile_mode'), '<a href="' . Html::link($moduleLink, ['action' => 'settings']) . '">' . Html::e(Page::lang('settings')) . '</a>')
            . '</div>';
    }

    /** @param string $value already-escaped HTML */
    private static function tile(string $value, string $label, string $detail): string
    {
        return '<div class="col-sm-6 col-md-3"><div class="panel panel-default agencydiscount-tile"><div class="panel-body">'
            . $value . '<div class="agencydiscount-tile-label">' . Html::e($label) . '</div>'
            . ($detail === '' ? '' : '<div class="agencydiscount-tile-detail text-muted">' . $detail . '</div>')
            . '</div></div></div>';
    }

    /** @param array<string,string> $amounts currency => amount */
    private static function perCurrency(array $amounts): string
    {
        if ($amounts === []) {
            return '<div class="agencydiscount-tile-value">&mdash;</div>';
        }
        ksort($amounts);
        $html = '';
        foreach ($amounts as $currency => $amount) {
            $html .= '<div class="agencydiscount-tile-value">' . Html::e(Html::money((string) $currency, $amount)) . '</div>';
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $get
     * @param list<AgencyStatus> $statuses
     */
    private function agencies(string $moduleLink, array $get, array $statuses): string
    {
        $filter = is_string($get['filter'] ?? null) && in_array($get['filter'], self::FILTERS, true) ? $get['filter'] : 'all';
        $query = is_string($get['q'] ?? null) ? trim($get['q']) : '';

        $html = $this->toolbar($moduleLink, $filter, $query, $statuses);
        if ($statuses === []) {
            return $html . '<p>' . Html::e(Page::lang('no_agencies')) . '</p>';
        }

        $rows = '';
        foreach ($statuses as $status) {
            if (!self::matches($status, $filter) || ($query !== '' && stripos($status->report->clientName, $query) === false)) {
                continue;
            }
            $rows .= $this->row($moduleLink, $status);
        }
        if ($rows === '') {
            return $html . '<p>' . Html::e(Page::lang('no_matches')) . '</p>';
        }
        $head = '';
        foreach (['col_client', 'col_spend', 'col_tier', 'col_to_next', 'col_next_invoice'] as $key) {
            $head .= '<th>' . Html::e(Page::lang($key)) . '</th>';
        }
        return $html . '<div class="table-responsive"><table class="table table-striped" id="agencydiscount-overview"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . self::recalculatedNote($statuses);
    }

    private static function matches(AgencyStatus $status, string $filter): bool
    {
        return match ($filter) {
            'tier' => $status->report->tier !== null,
            'waiting' => $status->isWaiting(),
            'no_tiers' => !$status->report->currencyHasTiers,
            default => true,
        };
    }

    /** @param list<AgencyStatus> $statuses */
    private function toolbar(string $moduleLink, string $filter, string $query, array $statuses): string
    {
        $buttons = '';
        foreach (self::FILTERS as $key) {
            $count = count(array_filter($statuses, static fn (AgencyStatus $s): bool => self::matches($s, $key)));
            $buttons .= '<a class="btn btn-default btn-sm' . ($key === $filter ? ' active' : '') . '" href="' . Html::link($moduleLink, ['filter' => $key, 'q' => $query === '' ? null : $query]) . '">'
                . Html::e(Page::lang('filter_' . $key) . ' (' . $count . ')') . '</a>';
        }

        $hidden = '';
        parse_str((string) parse_url($moduleLink, PHP_URL_QUERY), $params);
        foreach ($params as $name => $value) {
            if (is_scalar($value)) {
                $hidden .= '<input type="hidden" name="' . Html::e((string) $name) . '" value="' . Html::e((string) $value) . '">';
            }
        }
        $search = '<form method="get" action="' . Html::e((string) (parse_url($moduleLink, PHP_URL_PATH) ?: '')) . '" class="form-inline agencydiscount-search">' . $hidden
            . '<input type="hidden" name="filter" value="' . Html::e($filter) . '">'
            . '<input type="search" class="form-control input-sm" name="q" value="' . Html::e($query) . '" placeholder="' . Html::e(Page::lang('filter_name')) . '"> '
            . '<button type="submit" class="btn btn-default btn-sm">' . Html::e(Page::lang('filter_apply')) . '</button></form>';

        $recalculate = '<form method="post" class="form-inline agencydiscount-recalculate">' . $this->tokenField
            . '<input type="hidden" name="action" value="' . self::ACTION_RECALCULATE . '">'
            . '<button type="submit" class="btn btn-default btn-sm">' . Html::e(Page::lang('recalculate')) . '</button></form>';

        return '<div class="agencydiscount-toolbar"><div class="btn-group">' . $buttons . '</div> ' . $search . ' ' . $recalculate . '</div>';
    }

    private function row(string $moduleLink, AgencyStatus $status): string
    {
        $report = $status->report;
        $class = $status->isWaiting() ? ' class="warning"' : (!$report->currencyHasTiers ? ' class="danger"' : '');
        // Five columns, each at most two lines at 1440 wide (a long client name may take two on its
        // own): the start state and the detail link sit under the tier badge, the amounts under the
        // invoice date; the recalculation time is the spend's tooltip and the line under the table.
        $cells = [
            '<a href="clientssummary.php?userid=' . $report->clientId . '">' . Html::e($report->clientName) . '</a>',
            '<span class="agencydiscount-nowrap" title="' . Html::e(sprintf(Page::lang('recalculated_at'), $status->agency['calculatedAt'] ?? Page::lang('never'))) . '">'
                . Html::e(Html::money($report->currency, $report->monthlySpend)) . '</span>',
            self::tierBadge($status)
                . '<br><small class="agencydiscount-nowrap">' . Page::startsCell($status) . ' &middot; '
                . '<a href="' . Html::link($moduleLink, ['action' => 'agency', 'id' => $report->clientId]) . '">' . Html::e(Page::lang('detail')) . '</a></small>',
            self::progressCell($status),
            self::nextInvoiceStacked($status),
        ];
        return '<tr' . $class . '><td>' . implode('</td><td>', $cells) . '</td></tr>';
    }

    /**
     * One line under the table: when the figures were recalculated (the oldest and newest time when
     * the agencies differ), so the rows do not each carry a timestamp.
     *
     * @param list<AgencyStatus> $statuses
     */
    public static function recalculatedNote(array $statuses): string
    {
        if ($statuses === []) {
            return '';
        }
        $times = [];
        foreach ($statuses as $status) {
            if ($status->agency['calculatedAt'] !== null) {
                $times[] = $status->agency['calculatedAt'];
            }
        }
        sort($times);
        if ($times === []) {
            $text = Page::lang('recalculated_note_never');
        } elseif ($times[0] === $times[count($times) - 1]) {
            $text = sprintf(Page::lang('recalculated_note'), $times[0]);
        } else {
            $text = sprintf(Page::lang('recalculated_note_range'), $times[0], $times[count($times) - 1]);
        }
        return '<p class="text-muted"><small>' . Html::e($text) . '</small></p>';
    }

    /** The next invoice's date on one line and what it carries on the next ("ZAR 90.00 off ZAR 900.00"). */
    public static function nextInvoiceStacked(AgencyStatus $status): string
    {
        $first = $status->nextInvoice();
        if ($first === null) {
            return Html::e(sprintf(Page::lang('next_invoice_none'), AgencyStatus::PREVIEW_DAYS));
        }
        $currency = $status->report->currency;
        $base = Html::money($currency, $first['base']);
        $detail = $first['percent'] === null
            ? sprintf(Page::lang('next_invoice_detail_no_discount'), $base)
            : sprintf(Page::lang('next_invoice_detail'), Html::money($currency, $first['discount']), $base);
        return '<span class="agencydiscount-nowrap">' . Html::e($first['date']) . '</span><br><small class="agencydiscount-nowrap">' . Html::e($detail) . '</small>';
    }

    /** A Bootstrap label: the tier and percentage, "No tier", or the missing-tiers warning. */
    public static function tierBadge(AgencyStatus $status): string
    {
        $report = $status->report;
        if (!$report->currencyHasTiers) {
            return '<span class="label label-danger">' . Html::e(sprintf(Page::lang('no_tiers_for'), $report->currency)) . '</span>';
        }
        if ($report->tier === null) {
            return '<span class="label label-default">' . Html::e(Page::lang('no_tier')) . '</span>';
        }
        return '<span class="label label-success">' . Html::e(sprintf(Page::lang('tier_badge'), $report->tier->index, Money::formatPercent($report->tier->percent))) . '</span>';
    }

    /** A progress bar through the current band and the amount still needed, or "top tier". */
    public static function progressCell(AgencyStatus $status): string
    {
        $percent = $status->progressPercent();
        if ($percent === null) {
            return '&mdash;';
        }
        $bar = '<div class="progress agencydiscount-progress"><div class="progress-bar" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100" style="width: ' . $percent . '%"></div></div>';
        return $bar . '<small>' . Html::e(self::toNextTier($status)) . '</small>';
    }

    /** "ABC 987.09 to tier 2 (10%)", "top tier", or '' without tiers. */
    public static function toNextTier(AgencyStatus $status): string
    {
        $next = $status->report->nextTier;
        $shortfall = $status->shortfall();
        if (!$status->report->currencyHasTiers) {
            return '';
        }
        if ($next === null || $shortfall === null) {
            return Page::lang('top_tier');
        }
        return sprintf(Page::lang('to_next_tier'), Html::money($status->report->currency, $shortfall), $next->index, Money::formatPercent($next->percent));
    }

    /** @param list<AgencyStatus> $statuses */
    private function attention(string $moduleLink, array $statuses): string
    {
        $items = [];
        if ($this->module->config->dryRun) {
            $days = $this->dryRunDays($statuses);
            $text = match (true) {
                $days === null => Page::lang('attention_dry_run'),
                $days['exact'] => sprintf(Page::lang('attention_dry_run_days'), $days['days']),
                default => sprintf(Page::lang('attention_dry_run_at_least'), $days['days']),
            };
            $items[] = [$text, Html::link($moduleLink, ['action' => 'settings'])];
        }
        foreach (ConfigWarnings::texts($this->module->repo, $this->module->config) as $text) {
            $items[] = [$text, Html::link($moduleLink, ['action' => 'settings'])];
        }

        $withoutTiers = [];
        foreach ($statuses as $status) {
            if (!$status->report->currencyHasTiers) {
                $withoutTiers[$status->report->currency] = ($withoutTiers[$status->report->currency] ?? 0) + 1;
            }
        }
        ksort($withoutTiers);
        foreach ($withoutTiers as $currency => $count) {
            $items[] = [sprintf(Page::lang($count === 1 ? 'attention_no_tiers_one' : 'attention_no_tiers'), $currency, $count), Html::link($moduleLink, ['action' => 'settings'])];
        }

        foreach ($statuses as $status) {
            $next = $status->report->nextTier;
            if ($next === null || !$status->isNearNextTier()) {
                continue;
            }
            $items[] = [
                sprintf(Page::lang('attention_near_tier'), $status->report->clientName, Html::money($status->report->currency, $status->shortfall() ?? '0.00'), $next->index, Money::formatPercent($next->percent)),
                Html::link($moduleLink, ['action' => 'agency', 'id' => $status->report->clientId]),
            ];
        }

        $list = '';
        foreach ($items as [$text, $href]) {
            $list .= '<a class="list-group-item" href="' . $href . '">' . Html::e($text) . '</a>';
        }
        if ($list === '') {
            $list = '<div class="list-group-item text-muted">' . Html::e(Page::lang('attention_none')) . '</div>';
        }
        return '<div class="panel panel-default"><div class="panel-heading">' . Html::e(Page::lang('attention')) . '</div><div class="list-group">' . $list . '</div></div>';
    }

    /**
     * How long dry-run has been on: from the newest mode switch when the log has one, else from the
     * first approval (a lower bound), else unknown.
     *
     * @param list<AgencyStatus> $statuses
     * @return array{days:int, exact:bool}|null
     */
    private function dryRunDays(array $statuses): ?array
    {
        $today = $this->module->clock->now()->setTime(0, 0);
        foreach ($this->module->repo->logEntries(null, self::MODE_SCAN) as $entry) {
            if ($entry['event'] === 'mode_changed') {
                if (($entry['detail']['to'] ?? null) !== 'dry-run') {
                    break; // switched to live since; the dry-run now on started before this log window
                }
                return ['days' => self::daysBetween($entry['createdAt'], $today), 'exact' => true];
            }
        }
        $first = null;
        foreach ($statuses as $status) {
            $approvedAt = $status->agency['approvedAt'];
            if ($approvedAt !== null && ($first === null || $approvedAt < $first)) {
                $first = $approvedAt;
            }
        }
        return $first === null ? null : ['days' => self::daysBetween($first, $today), 'exact' => false];
    }

    private static function daysBetween(string $datetime, \DateTimeImmutable $today): int
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($datetime, 0, 10));
        return $from === false ? 0 : max(0, (int) $from->diff($today)->days);
    }

    /** @param list<AgencyStatus> $statuses */
    private function recent(string $moduleLink, array $statuses): string
    {
        $clients = [];
        foreach ($statuses as $status) {
            $clients[$status->report->clientId] = ['name' => $status->report->clientName, 'currency' => $status->report->currency];
        }
        $entries = $this->module->repo->logEntries(null, self::RECENT_ENTRIES);
        $clients = self::clientsFor($this->module->repo, $entries, $clients);
        $list = '';
        foreach ($entries as $entry) {
            $list .= '<div class="list-group-item"><small class="text-muted">' . Html::e($entry['createdAt']) . '</small><br>' . Html::e(LogEntry::sentence($entry, $clients)) . '</div>';
        }
        if ($list === '') {
            $list = '<div class="list-group-item text-muted">' . Html::e(Page::lang('activity_none')) . '</div>';
        }
        return '<div class="panel panel-default"><div class="panel-heading">' . Html::e(Page::lang('activity')) . '</div><div class="list-group">' . $list . '</div>'
            . '<div class="panel-footer"><a href="' . Html::link($moduleLink, ['action' => 'log']) . '">' . Html::e(Page::lang('activity_all')) . '</a></div></div>';
    }

    /**
     * Names and currencies for every client the entries mention: the known agencies, plus a lookup
     * for any other client (a revoked agency, say).
     *
     * @param list<array{id:int, clientId:?int, invoiceId:?int, event:string, detail:array<string,mixed>, actor:string, createdAt:string}> $entries
     * @param array<int, array{name:string, currency:string}> $clients
     * @return array<int, array{name:string, currency:string}>
     */
    public static function clientsFor(Repository $repo, array $entries, array $clients = []): array
    {
        foreach ($entries as $entry) {
            $id = $entry['clientId'];
            if ($id === null || isset($clients[$id])) {
                continue;
            }
            $client = $repo->client($id);
            if ($client !== null) {
                $clients[$id] = ['name' => $client['name'], 'currency' => $client['currency']];
            }
        }
        return $clients;
    }

    private static function assets(): string
    {
        return <<<'HTML'
<style>
.agencydiscount-tiles .agencydiscount-tile .panel-body{padding:12px 15px}
.agencydiscount-tile-value{font-size:22px;font-weight:bold;line-height:1.2}
.agencydiscount-tile-label{color:#777;margin-top:2px}
.agencydiscount-tile-detail{font-size:12px;margin-top:4px}
.agencydiscount-toolbar{margin-bottom:10px}
.agencydiscount-toolbar .btn-group,.agencydiscount-toolbar form{display:inline-block;vertical-align:middle;margin-right:8px;margin-bottom:6px}
.agencydiscount-progress{height:8px;margin:0 0 3px;min-width:120px}
#agencydiscount-overview td{vertical-align:middle}
#agencydiscount-overview .agencydiscount-nowrap{white-space:nowrap}
</style>
HTML;
    }
}
