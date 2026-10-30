<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\Config;
use AgencyDiscount\Repository;

/**
 * What a valid configuration refers to that WHMCS does not have: product group, product or add-on
 * ids WHMCS has no row for, and tiers for a currency WHMCS does not have. Not errors (the engine
 * runs without them, they just do nothing), so the Overview lists them under "Needs attention"
 * and the Settings tab lists them after a save, each pointing at the Settings tab where the id
 * or the currency can be removed.
 */
final class ConfigWarnings
{
    /** @return list<string> one sentence per finding, in the order groups, products, add-ons, currencies */
    public static function texts(Repository $repo, Config $config): array
    {
        $texts = [];
        foreach ([['group', 'product_groups', $config->productGroupIds], ['product', 'products', $config->productIds], ['addon', 'addons', $config->addonIds]] as [$kind, $key, $ids]) {
            if ($ids === []) {
                continue;
            }
            $names = $repo->lookupNames($kind, $ids);
            $missing = array_values(array_filter($ids, static fn (int $id): bool => !isset($names[$id])));
            if ($missing !== []) {
                $texts[] = sprintf(Page::lang('warn_missing_' . $key), implode(', ', $missing));
            }
        }
        $known = $repo->currencyCodes();
        foreach ($config->tiers->currencies() as $code) {
            if (!in_array($code, $known, true)) {
                $texts[] = sprintf(Page::lang('warn_unknown_currency'), $code);
            }
        }
        return $texts;
    }
}
