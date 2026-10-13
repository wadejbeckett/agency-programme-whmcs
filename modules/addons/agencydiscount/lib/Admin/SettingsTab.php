<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\ClientNotFound;
use AgencyDiscount\Config;
use AgencyDiscount\ConfigException;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
use AgencyDiscount\SpendCalculator;
use AgencyDiscount\SpendReport;
use AgencyDiscount\Tiers;

/**
 * The Settings tab: the mode card and one form for tiers, qualifying items, statuses, behaviour
 * and invoice line text, with an impact preview. Saves the same tbladdonmodules keys the engine
 * reads, so the CLI profile and the WHMCS Configure form stay valid.
 *
 * POST actions (all carry the WHMCS admin token, checked by the caller):
 *   action=mode, dry_run=on|''        switch dry-run on or off (its own small form)
 *   action=settings_preview           validate the form and show the impact table, save nothing
 *   action=settings_save              validate and save; problems are shown inline, nothing saved
 *   copy_to=CODE                      fill CODE's tier rows from the default currency's at the WHMCS rate, save nothing
 *
 * After a save or a mode switch $module is rebuilt on the new configuration; the caller renders
 * the rest of the page from it.
 *
 * @phpstan-import-type TierRow from SettingsForm
 */
final class SettingsTab
{
    public const ACTION_MODE = 'mode';
    public const ACTION_PREVIEW = 'settings_preview';
    public const ACTION_SAVE = 'settings_save';
    public const FIELD_COPY_TO = 'copy_to';
    public const CONFIGURE_LINK = 'configaddonmods.php#agencydiscount';

    private const PREVIEW_DAYS = 35;
    private const EXAMPLE_PERCENT = '10';
    private const EXAMPLE_COUNT = 3;

    public function __construct(
        public Module $module,
        private readonly string $tokenField,
    ) {
    }

    /** @param array<string, mixed> $post */
    public function handle(array $post, string $actor): string
    {
        $action = is_string($post['action'] ?? null) ? $post['action'] : '';
        $copyTo = is_string($post[self::FIELD_COPY_TO] ?? null) ? $post[self::FIELD_COPY_TO] : null;
        $notice = '';
        $problems = [];
        $form = null;
        $impact = '';

        if ($action === self::ACTION_MODE) {
            $notice = $this->switchMode(Config::yesNo($post['dry_run'] ?? '', true), $actor);
        } elseif ($copyTo !== null) {
            [$form, $notice, $problems] = $this->copyTiers(SettingsForm::fromPost($post), $copyTo);
        } elseif ($action === self::ACTION_PREVIEW || $action === self::ACTION_SAVE) {
            $form = SettingsForm::fromPost($post);
            try {
                $config = $form->validate($this->module->config->dryRun);
                if ($action === self::ACTION_SAVE) {
                    $notice = $this->save($form, $config, $actor);
                    $form = null;
                } else {
                    $impact = $this->impact($config);
                }
            } catch (ConfigException $e) {
                $problems = $e->problems();
            }
        }
        $form ??= SettingsForm::fromConfig($this->module->config);

        return $notice . self::problemList($problems, null) . $this->modeCard() . $this->form($form, $impact, $problems) . self::assets();
    }

    private function switchMode(bool $dryRun, string $actor): string
    {
        $label = Page::lang($dryRun ? 'mode_dry_run' : 'mode_live');
        if ($this->module->config->dryRun === $dryRun) {
            return self::alert('info', sprintf(Page::lang('mode_unchanged'), $label));
        }
        $repo = $this->module->repo;
        $repo->saveSettings(['dry_run' => $dryRun ? 'on' : '']);
        $repo->log([
            'clientId' => null,
            'invoiceId' => null,
            'event' => 'mode_changed',
            'detail' => ['from' => $dryRun ? 'live' : 'dry-run', 'to' => $dryRun ? 'dry-run' : 'live'],
            'actor' => $actor,
            'createdAt' => $this->module->clock->now()->format('Y-m-d H:i:s'),
        ]);
        $this->module->activity->log('Agency Discount: switched to ' . ($dryRun ? 'dry-run' : 'live') . ' mode');
        $this->module = new Module($repo, SettingsForm::fromConfig($this->module->config)->validate($dryRun), $this->module->clock, $this->module->activity);
        return self::alert('success', sprintf(Page::lang('mode_switched'), $label));
    }

    /** Writes the form's keys when any differs from what is stored, and logs the keys that changed. */
    private function save(SettingsForm $form, Config $config, string $actor): string
    {
        $settings = $form->toSettings();
        $settings['tiers'] = $config->tiers->toJson();
        $repo = $this->module->repo;
        $current = $repo->settings() + Config::defaults();
        $changed = [];
        foreach ($settings as $key => $value) {
            if (($current[$key] ?? '') !== $value) {
                $changed[$key] = ['from' => $current[$key] ?? '', 'to' => $value];
            }
        }
        if ($changed === []) {
            return self::alert('info', Page::lang('saved_nothing'));
        }
        $repo->saveSettings($settings);
        $repo->log([
            'clientId' => null,
            'invoiceId' => null,
            'event' => 'settings_changed',
            'detail' => ['changed' => $changed],
            'actor' => $actor,
            'createdAt' => $this->module->clock->now()->format('Y-m-d H:i:s'),
        ]);
        $keys = implode(', ', array_keys($changed));
        $this->module->activity->log('Agency Discount: settings changed: ' . $keys);
        $this->module = new Module($repo, $config, $this->module->clock, $this->module->activity);
        return self::alert('success', sprintf(Page::lang('saved'), $keys));
    }

    /** @return array{SettingsForm, string, list<string>} the form (target rows replaced on success), a notice, problems */
    private function copyTiers(SettingsForm $form, string $target): array
    {
        $default = null;
        $to = null;
        foreach ($this->module->repo->currencies() as $currency) {
            if ($currency['isDefault']) {
                $default = $currency;
            }
            if ($currency['code'] === $target) {
                $to = $currency;
            }
        }
        if ($default === null || $to === null || $to['code'] === $default['code']) {
            return [$form, self::alert('danger', sprintf(Page::lang('tiers_copy_no_rate'), $target)), []];
        }
        $source = $form->tiers[$default['code']] ?? [];
        if ($source === []) {
            return [$form, self::alert('danger', sprintf(Page::lang('tiers_copy_source_empty'), $default['code'])), []];
        }
        try {
            $rows = SettingsForm::convertedTiers($default['code'], $source, $to['rate']);
        } catch (ConfigException $e) {
            return [$form, self::alert('danger', sprintf(Page::lang('tiers_copy_source_invalid'), $default['code'])), $e->problems()];
        }
        return [
            $form->withTiers($to['code'], $rows),
            self::alert('info', sprintf(Page::lang('tiers_copied'), $default['code'], $to['code'], $to['rate'])),
            [],
        ];
    }

    private function modeCard(): string
    {
        $dryRun = $this->module->config->dryRun;
        $last = null;
        foreach ($this->module->repo->approvedAgencies() as $agency) {
            if ($agency['calculatedAt'] !== null && ($last === null || $agency['calculatedAt'] > $last)) {
                $last = $agency['calculatedAt'];
            }
        }
        $confirm = $dryRun ? ' onclick="return confirm(' . Html::e(json_encode(Page::lang('mode_confirm_live'), JSON_THROW_ON_ERROR)) . ')"' : '';
        return '<div class="panel panel-default" id="agencydiscount-mode"><div class="panel-body">'
            . '<h4>' . Html::e(Page::lang('mode')) . ': <span class="label label-' . ($dryRun ? 'warning' : 'success') . '">' . Html::e(Page::lang($dryRun ? 'mode_dry_run' : 'mode_live')) . '</span></h4>'
            . '<p>' . Html::e(Page::lang($dryRun ? 'mode_dry_run_help' : 'mode_live_help')) . '</p>'
            . '<p class="text-muted">' . Html::e(Page::lang('mode_last_recalculation')) . ': ' . Html::e($last ?? Page::lang('never'))
            . ' &middot; ' . Html::e(Page::lang('mode_invoices_checked')) . ': ' . $this->module->repo->invoicesChecked() . '</p>'
            . '<form method="post" class="form-inline">' . $this->tokenField
            . '<input type="hidden" name="action" value="' . self::ACTION_MODE . '">'
            . '<input type="hidden" name="dry_run" value="' . ($dryRun ? '' : 'on') . '">'
            . '<button type="submit" class="btn ' . ($dryRun ? 'btn-primary' : 'btn-default') . '"' . $confirm . '>'
            . Html::e(Page::lang($dryRun ? 'mode_switch_live' : 'mode_switch_dry_run')) . '</button></form>'
            . '</div></div>';
    }

    /** @param list<string> $problems */
    private function form(SettingsForm $form, string $impact, array $problems): string
    {
        // The first submit button in the form is the one Enter triggers: preview, never save.
        return '<form method="post" id="agencydiscount-settings">' . $this->tokenField
            . '<button type="submit" name="action" value="' . self::ACTION_PREVIEW . '" class="agencydiscount-default-submit" tabindex="-1" aria-hidden="true"></button>'
            . $this->tiersSection($form, $problems)
            . $this->countsSection($form)
            . $this->statusesSection($form, $problems)
            . $this->behaviourSection($form, $problems)
            . $this->impactSection($impact)
            . $this->actions()
            . '</form>';
    }

    /** @param list<string> $problems */
    private function tiersSection(SettingsForm $form, array $problems): string
    {
        $currencies = [];
        $default = null;
        foreach ($this->module->repo->currencies() as $currency) {
            if (preg_match('/^[A-Z]{3}$/', $currency['code']) !== 1) {
                continue; // the tiers setting only accepts three-letter codes
            }
            $currencies[$currency['code']] = $currency;
            if ($currency['isDefault']) {
                $default = $currency;
            }
        }
        $codes = array_keys($currencies);
        foreach (array_keys($form->tiers) as $code) {
            if (!isset($currencies[$code])) {
                $codes[] = $code; // tiers for a currency WHMCS no longer has: shown so they can be removed
            }
        }
        if ($codes === []) {
            return '<h4>' . Html::e(Page::lang('tiers')) . '</h4><p>' . Html::e(Page::lang('tiers_none')) . '</p>';
        }

        $tabs = '';
        $panes = '';
        foreach ($codes as $i => $code) {
            $known = isset($currencies[$code]);
            $label = $code;
            if ($default !== null && $code === $default['code']) {
                $label .= ' (' . Page::lang('tiers_default_currency') . ')';
            } elseif (!$known) {
                $label .= ' (' . Page::lang('tiers_not_in_whmcs') . ')';
            }
            $tabs .= '<li' . ($i === 0 ? ' class="active"' : '') . '><a href="#agencydiscount-tiers-' . Html::e($code) . '" data-agencydiscount-tab="' . Html::e($code) . '">' . Html::e($label) . '</a></li>';
            $panes .= $this->tierPane($code, $form->tiers[$code] ?? [], $i === 0, $known && $default !== null && $code !== $default['code'] ? $default['code'] : null, $currencies[$code]['rate'] ?? null, $problems);
        }

        return '<h4>' . Html::e(Page::lang('tiers')) . '</h4>'
            . '<p class="text-muted">' . Html::e(Page::lang('tiers_help')) . '</p>'
            . '<ul class="nav nav-pills" id="agencydiscount-currencies">' . $tabs . '</ul>'
            . '<div class="tab-content">' . $panes . '</div>'
            . '<template id="agencydiscount-tier-row">' . self::tierRow('__CODE__', '__N__', ['above' => '', 'percent' => ''], true) . '</template>';
    }

    /**
     * @param list<TierRow> $rows
     * @param string|null $copyFrom the default currency's code when a copy button belongs on this pane
     * @param list<string> $problems
     */
    private function tierPane(string $code, array $rows, bool $active, ?string $copyFrom, ?string $rate, array $problems): string
    {
        $body = '';
        foreach ($rows as $i => $row) {
            $body .= self::tierRow($code, (string) $i, $row, false);
        }
        $copy = '';
        if ($copyFrom !== null && $rate !== null) {
            $copy = ' <button type="submit" class="btn btn-default btn-sm" name="' . self::FIELD_COPY_TO . '" value="' . Html::e($code) . '">'
                . Html::e(sprintf(Page::lang('tiers_copy'), $copyFrom, $code, $rate)) . '</button>';
        }
        return '<div class="tab-pane' . ($active ? ' active' : '') . '" id="agencydiscount-tiers-' . Html::e($code) . '" data-agencydiscount-pane="' . Html::e($code) . '">'
            . self::problemList($problems, 'tiers: ' . $code . ' ')
            . '<div class="table-responsive"><table class="table table-condensed agencydiscount-tiers" data-agencydiscount-tiers="' . Html::e($code) . '">'
            . '<thead><tr><th>' . Html::e(Page::lang('tier')) . '</th><th>' . Html::e(Page::lang('tier_above')) . ' (' . Html::e($code) . ')</th><th>' . Html::e(Page::lang('tier_percent')) . '</th><th></th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div>'
            . '<p><button type="button" class="btn btn-default btn-sm" data-agencydiscount-add="' . Html::e($code) . '" data-next="' . count($rows) . '">' . Html::e(Page::lang('tier_add')) . '</button>' . $copy . '</p>'
            . self::bandPreview($code, $rows)
            . '</div>';
    }

    /** @param TierRow $row */
    private static function tierRow(string $code, string $index, array $row, bool $template): string
    {
        $code = $template ? $code : Html::e($code);
        $name = 'tiers[' . $code . '][' . $index . ']';
        return '<tr><td class="agencydiscount-tier-index"></td>'
            . '<td><input type="text" inputmode="decimal" class="form-control input-sm" name="' . $name . '[above]" value="' . Html::e($row['above']) . '"></td>'
            . '<td><input type="text" inputmode="decimal" class="form-control input-sm" name="' . $name . '[percent]" value="' . Html::e($row['percent']) . '"></td>'
            . '<td><button type="button" class="btn btn-link btn-sm" data-agencydiscount-remove>' . Html::e(Page::lang('tier_remove')) . '</button></td></tr>';
    }

    /**
     * The bands the rows define, each with a worked example; nothing while the rows are not a valid table.
     *
     * @param list<TierRow> $rows
     */
    private static function bandPreview(string $code, array $rows): string
    {
        if ($rows === []) {
            return '<p class="text-warning">' . Html::e(Page::lang('tiers_none')) . '</p>';
        }
        try {
            $tiers = Tiers::fromJson(SettingsForm::tiersJson([$code => $rows]))->forCurrency($code);
        } catch (ConfigException) {
            return '';
        }
        $body = '';
        foreach ($tiers as $i => $tier) {
            $next = $tiers[$i + 1] ?? null;
            if ($next === null) {
                $band = sprintf(Page::lang('tiers_band_from'), Html::money($code, $tier->above));
                $example = Money::isPositive($tier->above) ? Money::roundHalfUp(bcmul($tier->above, '1.5', 4)) : '100.00';
            } else {
                $band = sprintf(Page::lang('tiers_band_from_to'), Html::money($code, $tier->above), Html::money($code, $next->above));
                $example = Money::roundHalfUp(bcdiv(bcadd($tier->above, $next->above, 2), '2', 4));
            }
            $body .= '<tr><td>' . $tier->index . '</td><td>' . Html::e($band) . '</td><td>' . Html::e(Money::formatPercent($tier->percent)) . '%</td>'
                . '<td>' . Html::e(sprintf(Page::lang('tiers_example_line'), Html::money($code, $example), Html::money($code, Money::percentOf($example, $tier->percent)))) . '</td></tr>';
        }
        return '<h5>' . Html::e(Page::lang('tiers_preview')) . '</h5><div class="table-responsive"><table class="table table-condensed table-striped agencydiscount-bands">'
            . '<thead><tr><th>' . Html::e(Page::lang('tier')) . '</th><th>' . Html::e(Page::lang('tiers_band')) . '</th><th>' . Html::e(Page::lang('tier_percent')) . '</th><th>' . Html::e(Page::lang('tiers_example')) . '</th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div>';
    }

    private function countsSection(SettingsForm $form): string
    {
        $repo = $this->module->repo;
        $groups = $repo->productGroups();
        usort($groups, static fn (array $a, array $b): int => ($a['hidden'] ? 1 : 0) <=> ($b['hidden'] ? 1 : 0)); // stable: hidden last, order kept
        $groupNames = [];
        $boxes = '';
        foreach ($groups as $group) {
            $groupNames[$group['id']] = $group['name'];
            $notes = [];
            if ($group['hidden']) {
                $notes[] = Page::lang('groups_hidden');
            }
            $notes[] = sprintf(Page::lang('groups_active'), $group['activeServices']);
            $boxes .= self::checkbox('product_groups[]', (string) $group['id'], in_array($group['id'], $form->groupIds, true), $group['name'], implode(', ', $notes));
        }
        $boxes .= self::missing('product_groups[]', $form->groupIds, array_keys($groupNames));

        $products = '';
        $byGroup = [];
        foreach ($repo->products() as $product) {
            $byGroup[$product['groupId']][] = $product;
        }
        foreach ($byGroup as $groupId => $list) {
            $products .= '<div data-agencydiscount-group><h5>' . Html::e($groupNames[$groupId] ?? sprintf(Page::lang('group_unknown'), $groupId)) . '</h5>';
            foreach ($list as $product) {
                $products .= self::checkbox('products[]', (string) $product['id'], in_array($product['id'], $form->productIds, true), $product['name'], '');
            }
            $products .= '</div>';
        }
        $products .= self::missing('products[]', $form->productIds, array_column($repo->products(), 'id'));

        $addons = '<div data-agencydiscount-group>';
        foreach ($repo->addonCatalog() as $addon) {
            $addons .= self::checkbox('addons[]', (string) $addon['id'], in_array($addon['id'], $form->addonIds, true), $addon['name'], '');
        }
        $addons .= '</div>' . self::missing('addons[]', $form->addonIds, array_column($repo->addonCatalog(), 'id'));

        return '<h4>' . Html::e(Page::lang('counts')) . '</h4>'
            . '<p class="text-muted">' . Html::e(Page::lang('counts_help')) . '</p>'
            . '<div class="row">'
            . '<div class="col-md-4"><h5>' . Html::e(Page::lang('groups')) . '</h5><div class="agencydiscount-picker">' . $boxes . '</div></div>'
            . '<div class="col-md-4"><h5>' . Html::e(Page::lang('products')) . '</h5><p class="text-muted">' . Html::e(Page::lang('products_help')) . '</p>'
            . self::filter('products') . '<div class="agencydiscount-picker" data-agencydiscount-list="products">' . $products . '</div></div>'
            . '<div class="col-md-4"><h5>' . Html::e(Page::lang('addons')) . '</h5>'
            . self::filter('addons') . '<div class="agencydiscount-picker" data-agencydiscount-list="addons">' . $addons . '</div></div>'
            . '</div>';
    }

    /** @param list<string> $problems */
    private function statusesSection(SettingsForm $form, array $problems): string
    {
        $boxes = '';
        foreach (array_values(array_unique(array_merge(SettingsForm::STATUSES, $form->statuses))) as $status) {
            $boxes .= '<label class="checkbox-inline"><input type="checkbox" name="statuses[]" value="' . Html::e($status) . '"'
                . (in_array($status, $form->statuses, true) ? ' checked' : '') . '> ' . Html::e($status) . '</label>';
        }
        return '<h4>' . Html::e(Page::lang('statuses')) . '</h4>'
            . '<p class="text-muted">' . Html::e(Page::lang('statuses_help')) . '</p>'
            . self::problemList($problems, 'statuses:')
            . '<div class="form-group">' . $boxes . '</div>';
    }

    /** @param list<string> $problems */
    private function behaviourSection(SettingsForm $form, array $problems): string
    {
        $switches = '';
        foreach (SettingsForm::SWITCHES as $key) {
            $switches .= '<div class="checkbox"><label><input type="checkbox" name="' . $key . '" value="on"' . (($form->switches[$key] ?? false) ? ' checked' : '') . '> '
                . Html::e(Page::lang('switch_' . $key)) . '</label> <span class="text-muted">' . Html::e(Page::lang('switch_' . $key . '_help')) . '</span></div>';
        }
        $example = str_replace(['{percent}', '{count}'], [self::EXAMPLE_PERCENT, (string) self::EXAMPLE_COUNT], $form->description);
        return '<h4>' . Html::e(Page::lang('behaviour')) . '</h4>' . $switches
            . '<div class="form-group"><label for="agencydiscount-description">' . Html::e(Page::lang('description')) . '</label>'
            . self::problemList($problems, 'description:')
            . '<input type="text" id="agencydiscount-description" name="description" class="form-control" value="' . Html::e($form->description) . '">'
            . '<p class="help-block">' . Html::e(Page::lang('description_help')) . ' ' . Html::e(Page::lang('description_example')) . ': <code>' . Html::e($example) . '</code></p></div>';
    }

    private function impactSection(string $impact): string
    {
        return '<h4>' . Html::e(Page::lang('impact')) . '</h4>'
            . '<p class="text-muted">' . Html::e(Page::lang('impact_help')) . '</p>'
            . $impact;
    }

    /** Each approved agency under the saved configuration and under $new. */
    private function impact(Config $new): string
    {
        $repo = $this->module->repo;
        $after = new SpendCalculator($repo, $new);
        $now = $this->module->clock->now();
        $rows = '';
        $changes = 0;
        foreach ($repo->approvedAgencies() as $agency) {
            try {
                $before = $this->module->spend()->forClient($agency['clientId']);
                $then = $after->forClient($agency['clientId']);
            } catch (ClientNotFound) {
                continue;
            }
            $changed = $before->tier?->index !== $then->tier?->index
                || $before->tier?->percent !== $then->tier?->percent
                || $before->currencyHasTiers !== $then->currencyHasTiers
                || $before->monthlySpend !== $then->monthlySpend;
            $changes += $changed ? 1 : 0;
            $spend = Html::e(Html::money($before->currency, $before->monthlySpend));
            if ($before->monthlySpend !== $then->monthlySpend) {
                $spend .= ' &rarr; ' . Html::e(Html::money($then->currency, $then->monthlySpend));
            }
            $rows .= '<tr' . ($changed ? ' class="warning"' : '') . '><td><a href="clientssummary.php?userid=' . $before->clientId . '">' . Html::e($before->clientName) . '</a></td>'
                . '<td>' . Html::e($before->currency) . '</td><td>' . $spend . '</td>'
                . '<td>' . Page::tierCell($before) . '</td><td>' . Page::tierCell($then) . '</td>'
                . '<td>' . $this->nextDiscount($before, $agency['effectiveFrom'], $now) . '</td><td>' . $this->nextDiscount($then, $agency['effectiveFrom'], $now) . '</td></tr>';
        }
        if ($rows === '') {
            return '<p>' . Html::e(Page::lang('impact_none')) . '</p>';
        }
        $head = '';
        foreach (['Client', 'Currency', 'Qualifying monthly spend', Page::lang('impact_tier_now'), Page::lang('impact_tier_after'), Page::lang('impact_discount_now'), Page::lang('impact_discount_after')] as $header) {
            $head .= '<th>' . Html::e($header) . '</th>';
        }
        return ($changes === 0 ? '<p>' . Html::e(Page::lang('impact_unchanged')) . '</p>' : '')
            . '<div class="table-responsive"><table class="table table-striped" id="agencydiscount-impact"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function nextDiscount(SpendReport $report, ?string $effectiveFrom, \DateTimeImmutable $now): string
    {
        $first = Preview::upcoming($report, $now, self::PREVIEW_DAYS, $effectiveFrom)[0] ?? null;
        if ($first === null) {
            return '&mdash;';
        }
        return Html::e($first['date'] . ': ' . Html::money($report->currency, $first['discount']));
    }

    private function actions(): string
    {
        return '<p class="agencydiscount-actions">'
            . '<button type="submit" name="action" value="' . self::ACTION_PREVIEW . '" class="btn btn-default">' . Html::e(Page::lang('preview')) . '</button> '
            . '<button type="submit" name="action" value="' . self::ACTION_SAVE . '" class="btn btn-primary">' . Html::e(Page::lang('save')) . '</button> '
            . '<a href="' . self::CONFIGURE_LINK . '">' . Html::e(Page::lang('advanced_json')) . '</a> '
            . '<span class="text-muted">' . Html::e(Page::lang('advanced_json_help')) . '</span></p>';
    }

    private static function checkbox(string $name, string $value, bool $checked, string $label, string $note): string
    {
        return '<label><input type="checkbox" name="' . $name . '" value="' . Html::e($value) . '"' . ($checked ? ' checked' : '') . '> ' . Html::e($label)
            . ($note === '' ? '' : ' <span class="text-muted">(' . Html::e($note) . ')</span>') . '</label>';
    }

    /**
     * Configured ids WHMCS has no row for, kept ticked and flagged so the admin can untick them.
     *
     * @param list<int> $configured
     * @param list<int> $known
     */
    private static function missing(string $name, array $configured, array $known): string
    {
        $html = '';
        foreach ($configured as $id) {
            if (!in_array($id, $known, true)) {
                $html .= self::checkbox($name, (string) $id, true, '#' . $id, Page::lang('not_found'));
            }
        }
        return $html === '' ? '' : '<div data-agencydiscount-group>' . $html . '</div>';
    }

    private static function filter(string $list): string
    {
        return '<input type="search" class="form-control input-sm" placeholder="' . Html::e(Page::lang('filter')) . '" data-agencydiscount-filter="' . $list . '">';
    }

    /**
     * The problems that concern one section (by prefix), or all of them (null prefix) at the top.
     *
     * @param list<string> $problems
     */
    private static function problemList(array $problems, ?string $prefix): string
    {
        $items = '';
        foreach ($problems as $problem) {
            if ($prefix === null || str_starts_with($problem, $prefix)) {
                $items .= '<li>' . Html::e($problem) . '</li>';
            }
        }
        if ($items === '') {
            return '';
        }
        return '<div class="alert alert-danger">' . ($prefix === null ? '<p>' . Html::e(Page::lang('problems')) . '</p>' : '') . '<ul>' . $items . '</ul></div>';
    }

    private static function alert(string $kind, string $text): string
    {
        return '<div class="alert alert-' . $kind . '">' . Html::e($text) . '</div>';
    }

    /** The tab's stylesheet and script: sub-tabs, add/remove tier rows, name filters. No framework. */
    private static function assets(): string
    {
        return <<<'HTML'
<style>
#agencydiscount-settings .agencydiscount-default-submit{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
#agencydiscount-settings .agencydiscount-picker{max-height:18em;overflow:auto;border:1px solid #ddd;border-radius:4px;padding:6px 10px;margin-bottom:10px}
#agencydiscount-settings .agencydiscount-picker h5{margin:8px 0 4px;font-weight:bold}
#agencydiscount-settings .agencydiscount-picker label{display:block;font-weight:normal;margin:0 0 2px}
#agencydiscount-settings .agencydiscount-tiers input{max-width:12em}
#agencydiscount-settings .agencydiscount-tiers td{vertical-align:middle}
#agencydiscount-settings .tab-content{margin-top:10px}
#agencydiscount-settings .checkbox .text-muted{margin-left:4px}
#agencydiscount-settings .agencydiscount-actions{margin-top:20px}
</style>
<script>
(function () {
  var root = document.getElementById('agencydiscount-settings');
  var template = document.getElementById('agencydiscount-tier-row');
  if (!root) { return; }
  function renumber(table) {
    var cells = table.querySelectorAll('tbody .agencydiscount-tier-index');
    for (var i = 0; i < cells.length; i++) { cells[i].textContent = String(i + 1); }
  }
  function activate(code) {
    var tabs = root.querySelectorAll('[data-agencydiscount-tab]');
    for (var i = 0; i < tabs.length; i++) { tabs[i].parentNode.className = tabs[i].getAttribute('data-agencydiscount-tab') === code ? 'active' : ''; }
    var panes = root.querySelectorAll('[data-agencydiscount-pane]');
    for (var j = 0; j < panes.length; j++) { panes[j].className = 'tab-pane' + (panes[j].getAttribute('data-agencydiscount-pane') === code ? ' active' : ''); }
  }
  var tables = root.querySelectorAll('[data-agencydiscount-tiers]');
  for (var t = 0; t < tables.length; t++) { renumber(tables[t]); }
  root.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-agencydiscount-tab]');
    if (tab) { e.preventDefault(); activate(tab.getAttribute('data-agencydiscount-tab')); return; }
    var remove = e.target.closest('[data-agencydiscount-remove]');
    if (remove) {
      var row = remove.closest('tr'), table = remove.closest('table');
      row.parentNode.removeChild(row);
      renumber(table);
      return;
    }
    var add = e.target.closest('[data-agencydiscount-add]');
    if (add && template) {
      var code = add.getAttribute('data-agencydiscount-add');
      var n = parseInt(add.getAttribute('data-next'), 10) || 0;
      add.setAttribute('data-next', String(n + 1));
      var tbody = root.querySelector('[data-agencydiscount-tiers="' + code + '"] tbody');
      var tr = document.createElement('tr');
      tr.innerHTML = template.innerHTML.split('__CODE__').join(code).split('__N__').join(String(n));
      tbody.appendChild(tr);
      renumber(tbody.parentNode);
      tr.querySelector('input').focus();
    }
  });
  root.addEventListener('input', function (e) {
    var filter = e.target.closest('[data-agencydiscount-filter]');
    if (!filter) { return; }
    var q = filter.value.toLowerCase();
    var list = root.querySelector('[data-agencydiscount-list="' + filter.getAttribute('data-agencydiscount-filter') + '"]');
    var groups = list.querySelectorAll('[data-agencydiscount-group]');
    for (var g = 0; g < groups.length; g++) {
      var labels = groups[g].querySelectorAll('label'), visible = 0;
      for (var i = 0; i < labels.length; i++) {
        var show = q === '' || labels[i].textContent.toLowerCase().indexOf(q) !== -1 || labels[i].querySelector('input').checked;
        labels[i].style.display = show ? '' : 'none';
        if (show) { visible++; }
      }
      groups[g].style.display = visible ? '' : 'none';
    }
  });
})();
</script>
HTML;
    }
}
