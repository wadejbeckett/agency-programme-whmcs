<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** What agencydiscount_upgrade() changes for an install at an older version, kept here so it runs against ArrayRepository in the tests. */
final class Upgrade
{
    /**
     * Runs the schema statements for $from and rewrites settings whose shipped default changed, and
     * returns one sentence per change for the activity log.
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
        $statements = Schema::upgradeStatements($from);
        foreach ($statements as $sql) {
            $repo->execute($sql);
        }
        if ($statements !== []) {
            $changes[] = 'mod_agencydiscount_invoices now holds one row per discount line (columns id, line and taxed added)';
        }
        if (version_compare($from, '0.2.1', '<')
            && ($repo->settings()['description'] ?? null) === Config::OLD_DEFAULT_DESCRIPTION) {
            $repo->saveSettings(['description' => Config::DEFAULT_DESCRIPTION]);
            $changes[] = 'invoice line text changed from the old default "' . Config::OLD_DEFAULT_DESCRIPTION . '" to "' . Config::DEFAULT_DESCRIPTION . '"';
        }
        return $changes;
    }
}
