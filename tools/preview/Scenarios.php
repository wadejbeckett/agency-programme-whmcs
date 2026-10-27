<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\PreviewHarness;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;

/**
 * Fixture data for the offline render harness: generic agency names with the shapes and
 * magnitudes of a real install (six ZAR agencies, one well above the top tier, one with no
 * qualifying services yet). Nothing here touches WHMCS or a database.
 */
final class Scenarios
{
    public const NOW = '2026-10-27 13:00:00';
    public const RECALCULATED = '2026-10-27 12:45:41';

    /** Monthly qualifying spend per agency, in the order the widget lists them. */
    private const SPENDS = ['10820.00', '4873.31', '340.00', '255.00', '85.00', '0.00'];
    private const NAMES = ['Agency One', 'Agency Two Digital Studio', 'Agency Three', 'Agency Four', 'Agency Five', 'Agency Six'];

    /** @return list<string> scenario names for the widget */
    public static function widgetScenarios(): array
    {
        return ['live', 'edge', 'long', 'empty', 'denied', 'unavailable'];
    }

    /** @return array<string,string> */
    public static function settings(bool $dryRun = false): array
    {
        return [
            'tiers' => '{"ZAR":[{"above":0,"percent":5},{"above":5000,"percent":10}]}',
            'product_groups' => '23',
            'products' => '',
            'addons' => '',
            'statuses' => 'Active',
            'dry_run' => $dryRun ? 'on' : '',
            'description' => 'Agency discount {percent}% on {count} qualifying {items}',
            'delete_data' => '',
            'activity_log' => 'on',
            'stack_with_promotions' => '',
            'access' => '1',
        ];
    }

    /**
     * live: the six-agency install. edge: dry-run, a start date, a currency without tiers.
     * long: twenty agencies, to see the list scroll. empty: no agencies.
     */
    public static function module(string $scenario): Module
    {
        $repo = new ArrayRepository([
            'currencies' => [1 => ['code' => 'ZAR', 'rate' => '1.00000', 'isDefault' => true], 3 => ['code' => 'EUR', 'rate' => '0.05389', 'isDefault' => false]],
            'groups' => [23 => 'Premium Shared'],
            'products' => [113 => ['gid' => 23, 'name' => 'sPremium-1']],
            'admins' => [1 => 1, 2 => 3],
            'settings' => self::settings($scenario === 'edge'),
        ]);
        $spends = match ($scenario) {
            'empty' => [],
            'long' => array_merge(self::SPENDS, ['1200.00', '980.00', '760.00', '640.00', '510.00', '425.00', '340.00', '255.00', '170.00', '170.00', '85.00', '85.00', '85.00', '0.00']),
            default => self::SPENDS,
        };
        foreach ($spends as $i => $spend) {
            $name = self::NAMES[$i] ?? sprintf('Agency %d', $i + 1);
            self::agency($repo, 101 + $i, $name, 'ZAR', 1, $spend, sprintf('2026-11-%02d', 1 + ($i % 5) * 4));
        }
        if ($scenario === 'edge') {
            $repo->agencies[103]['effectiveFrom'] = '2026-12-01';
            self::agency($repo, 201, 'Euro Agency', 'EUR', 3, '300.00', '2026-11-05');
        }
        return new Module($repo, Config::fromSettings($repo->settings), new FrozenClock(new \DateTimeImmutable(self::NOW)), new RecordingActivityLog());
    }

    private static function agency(ArrayRepository $repo, int $id, string $name, string $currency, int $currencyId, string $spend, string $nextInvoice): void
    {
        $repo->clients[$id] = ['id' => $id, 'name' => $name, 'currency' => $currency, 'currencyId' => $currencyId];
        if ($spend !== '0.00') {
            $repo->services[$id * 10] = ['clientId' => $id, 'id' => $id * 10, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'site' . $id . '.example', 'amount' => $spend, 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => $nextInvoice, 'promoId' => 0];
        }
        $repo->saveAgency(['clientId' => $id, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'calculatedAt' => self::RECALCULATED, 'updatedAt' => '2026-10-01 00:00:00']);
    }
}
