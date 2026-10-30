<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\PreviewHarness;

use AgencyDiscount\Admin\Page;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;

/**
 * Fixture data for the offline render harness: demo agencies with round figures (six ZAR
 * agencies, one well above the top tier, one with no qualifying services yet). Invented
 * values only; nothing here comes from or touches a WHMCS install or a database.
 */
final class Scenarios
{
    public const NOW = '2026-10-27 13:00:00';
    public const RECALCULATED = '2026-10-27 12:45:41';

    /** Monthly qualifying spend per agency, in the order the widget lists them. */
    private const SPENDS = ['12000.00', '6000.00', '2500.00', '1000.00', '500.00', '0.00'];
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
     * Stored settings that do not parse, for the repair page: a tier threshold that is not a number
     * and a product group id that is not a number (as an import or a hand edit in SQL could leave them).
     *
     * @return array<string,string>
     */
    public static function brokenSettings(): array
    {
        return [
            'tiers' => '{"ZAR":[{"above":0,"percent":5},{"above":"5k","percent":10}]}',
            'product_groups' => '23,premium',
        ] + self::settings(true);
    }

    /**
     * live: the six-agency install. edge: dry-run, a start date, a currency without tiers, a
     * product id and a tier currency WHMCS does not have. long: twenty agencies, to see the list
     * scroll. empty: no agencies.
     */
    public static function module(string $scenario): Module
    {
        $repo = self::repository($scenario);
        return new Module($repo, Config::fromSettings($repo->settings), self::clock(), new RecordingActivityLog());
    }

    /** The addon page for a scenario; 'broken' (the six agencies with brokenSettings()) gives the repair page. */
    public static function page(string $scenario): Page
    {
        return Page::forStoredSettings(self::repository($scenario), self::clock(), new RecordingActivityLog());
    }

    public static function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable(self::NOW));
    }

    public static function repository(string $scenario): ArrayRepository
    {
        $repo = new ArrayRepository([
            'currencies' => [1 => ['code' => 'ZAR', 'rate' => '1.00000', 'isDefault' => true], 3 => ['code' => 'EUR', 'rate' => '0.05389', 'isDefault' => false]],
            'groups' => [23 => 'Premium Shared'],
            'products' => [113 => ['gid' => 23, 'name' => 'sPremium-1']],
            'admins' => [1 => 1, 2 => 3],
            'settings' => match ($scenario) {
                'broken' => self::brokenSettings(),
                'edge' => ['products' => '777', 'tiers' => '{"ZAR":[{"above":0,"percent":5},{"above":5000,"percent":10}],"USD":[{"above":0,"percent":5}]}'] + self::settings(true),
                default => self::settings(false),
            },
        ]);
        $spends = match ($scenario) {
            'empty' => [],
            'long' => array_merge(self::SPENDS, ['4000.00', '3500.00', '3000.00', '2000.00', '1500.00', '1200.00', '800.00', '750.00', '600.00', '400.00', '300.00', '250.00', '100.00', '0.00']),
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
        return $repo;
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
