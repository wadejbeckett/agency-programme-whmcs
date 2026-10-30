<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** What agencydiscount_upgrade() changes for an install at an older version, kept here so it runs against ArrayRepository in the tests. */
final class Upgrade
{
    /** The setting 0.2.1 replaced with delete_data; its row was left stored until 0.3.0 deletes it. */
    public const RETIRED_SETTING = 'keep_data';

    /**
     * Runs the schema steps for $from, then the settings changes (a retired row deleted, a default
     * that changed rewritten), and returns one sentence per change for the activity log. Stored
     * values are never rewritten by a move: 0.3.0 moves nine settings off the Configure form and
     * their rows stay as they are.
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
            if (isset($step['index']) && $repo->hasIndex(...$step['index'])) {
                continue; // already there: a repeated upgrade from the same version, or a fresh table
            }
            foreach ($step['statements'] as $sql) {
                $repo->execute($sql);
            }
            $changes[] = $step['sentence'];
        }
        if (version_compare($from, '0.3.0', '<')) {
            if (array_key_exists(self::RETIRED_SETTING, $repo->settings())) {
                $repo->deleteSettings([self::RETIRED_SETTING]);
                $changes[] = 'the unread ' . self::RETIRED_SETTING . ' setting row deleted (replaced by delete_data in 0.2.1)';
            }
            $changes[] = 'tiers, qualifying items, statuses, mode, behaviour and invoice line text are now edited on the Settings tab only (the Configure form keeps the access list and the housekeeping settings); every stored value is kept';
        }
        if (version_compare($from, '0.2.1', '<')
            && ($repo->settings()['description'] ?? null) === Config::OLD_DEFAULT_DESCRIPTION) {
            $repo->saveSettings(['description' => Config::DEFAULT_DESCRIPTION]);
            $changes[] = 'invoice line text changed from the old default "' . Config::OLD_DEFAULT_DESCRIPTION . '" to "' . Config::DEFAULT_DESCRIPTION . '"';
        }
        return $changes;
    }
}
