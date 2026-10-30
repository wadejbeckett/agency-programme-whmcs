<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** What agencydiscount_upgrade() changes for an install at an older version, kept here so it runs against ArrayRepository in the tests. */
final class Upgrade
{
    /** The setting 0.2.1 replaced with delete_data; its row was left stored until 0.3.0 deletes it. */
    public const RETIRED_SETTING = 'keep_data';
    /** The id lists 0.3.0 cleans of a lone "0" (an entry 0.2.x accepted and matched nothing with; 0.3.0 rejects it). */
    private const ID_LISTS = ['product_groups', 'products', 'addons'];

    /**
     * What the administrator should know about an upgrade from $from that changes nothing stored:
     * 0.3.0 moves nine settings off the Configure form and their rows stay as they are. Logged by
     * agencydiscount_upgrade() beside the changes, not counted as one.
     *
     * @return list<string>
     */
    public static function notes(string $from): array
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $from) === 1 && version_compare($from, '0.3.0', '<')) {
            return ['tiers, qualifying items, statuses, mode, behaviour and invoice line text are now edited on the Settings tab only (the Configure form keeps the access list and the housekeeping settings); every stored value is kept'];
        }
        return [];
    }

    /**
     * Runs the schema steps for $from, then the settings changes (a retired row deleted, a default
     * that changed rewritten), and returns one sentence per change for the activity log. Stored
     * values are never rewritten by a move (see notes()).
     *
     * @param string $from the installed version WHMCS reports, for example "0.2.0"
     * @return list<string>
     * @throws \InvalidArgumentException when $from is not a version number, so no statement runs on a guess
     */
    public static function run(Repository $repo, string $from): array
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $from) !== 1) {
            throw new \InvalidArgumentException('the installed version "' . $from . '" is not a version number; nothing was changed');
        }
        $changes = [];
        foreach (Schema::upgradeSteps($from) as $step) {
            if (isset($step['unless']) && self::exists($repo, ...$step['unless'])) {
                continue; // already there: a repeated upgrade from the same version, or a fresh table
            }
            foreach ($step['statements'] as $sql) {
                $repo->execute($sql);
            }
            $changes[] = $step['sentence'];
        }
        if (version_compare($from, '0.3.0', '<')) {
            $settings = $repo->settings();
            if (array_key_exists(self::RETIRED_SETTING, $settings)) {
                $repo->deleteSettings([self::RETIRED_SETTING]);
                $changes[] = 'the unread ' . self::RETIRED_SETTING . ' setting row deleted (replaced by delete_data in 0.2.1)';
            }
            // A "0" in an id list matched nothing under 0.2.x and is a configuration problem from 0.3.0, which would stop
            // the discounts until the Settings tab is saved: drop it here instead, so behaviour is exactly as before.
            $cleaned = [];
            foreach (self::ID_LISTS as $key) {
                $value = $settings[$key] ?? null;
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
                $kept = array_values(array_filter($items, static fn (string $item): bool => preg_match('/^0+$/', $item) !== 1));
                if ($kept !== $items) {
                    $cleaned[$key] = implode(',', $kept);
                }
            }
            if ($cleaned !== []) {
                $repo->saveSettings($cleaned);
                $changes[] = 'a "0" removed from ' . implode(', ', array_keys($cleaned)) . ' (it matched nothing; 0.3.0 would reject it)';
            }
        }
        if (version_compare($from, '0.2.1', '<')
            && ($repo->settings()['description'] ?? null) === Config::OLD_DEFAULT_DESCRIPTION) {
            $repo->saveSettings(['description' => Config::DEFAULT_DESCRIPTION]);
            $changes[] = 'invoice line text changed from the old default "' . Config::OLD_DEFAULT_DESCRIPTION . '" to "' . Config::DEFAULT_DESCRIPTION . '"';
        }
        return $changes;
    }

    /** @param 'column'|'index' $kind */
    private static function exists(Repository $repo, string $kind, string $table, string $name): bool
    {
        return $kind === 'column' ? $repo->hasColumn($table, $name) : $repo->hasIndex($table, $name);
    }
}
