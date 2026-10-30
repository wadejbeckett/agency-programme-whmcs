<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\PreviewHarness;

use AgencyDiscount\Admin\Page;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\Money;
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

    /** The scenario the page renders (Overview, Settings, agency detail, Change log, client area) are made from. */
    public const PAGES = 'edge';
    /** The client whose agency detail and client-area page are rendered: Agency One, at the top tier. */
    public const DETAIL_CLIENT = 101;

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
     * live (also "demo", the screenshot set): the six-agency install. edge: dry-run, a start date,
     * a currency without tiers, a product id and a tier currency WHMCS does not have. long: twenty
     * agencies, to see the list scroll. empty: no agencies. Every scenario but "empty" carries a
     * handful of change-log entries and the discount records behind them.
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
            'demo' => self::SPENDS,
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
        if ($scenario !== 'empty') {
            self::history($repo);
        }
        return $repo;
    }

    /**
     * A plausible change log for the demo install, newest last: approvals, a settings save, the
     * switch to live, discounts applied and a tier change; with the invoice records the applied
     * entries describe. Invoice numbers and amounts are invented round figures.
     */
    private static function history(ArrayRepository $repo): void
    {
        $log = static function (string $at, ?int $client, ?int $invoice, string $event, array $detail, string $actor) use ($repo): void {
            $repo->log(['clientId' => $client, 'invoiceId' => $invoice, 'event' => $event, 'detail' => $detail, 'actor' => $actor, 'createdAt' => $at]);
        };
        $log('2026-10-01 09:00:00', 101, null, 'approved', ['note' => 'Partner since 2024'], 'admin:1');
        $log('2026-10-01 09:05:00', 102, null, 'approved', ['note' => ''], 'admin:1');
        $log('2026-10-01 09:30:00', null, null, 'settings_changed', ['changed' => ['tiers' => ['from' => '{}', 'to' => '{"ZAR":[{"above":0,"percent":5},{"above":5000,"percent":10}]}'], 'product_groups' => ['from' => '', 'to' => '23']]], 'admin:1');
        $log('2026-10-03 03:00:00', 101, 90001, 'dry_run', ['amount' => '1200.00', 'percent' => '10.00'], 'system');
        $log('2026-10-06 10:00:00', null, null, 'mode_changed', ['from' => 'dry-run', 'to' => 'live'], 'admin:1');
        $log('2026-10-07 03:00:00', 102, 90002, 'applied', ['amount' => '300.00', 'percent' => '5.00'], 'system');
        $log('2026-10-15 03:00:00', 102, null, 'tier_changed', ['from' => 1, 'fromPercent' => '5.00', 'to' => 2, 'toPercent' => '10.00', 'spend' => '6000.00'], 'cron:daily');
        $log('2026-10-20 03:00:00', 101, 90003, 'applied', ['amount' => '1200.00', 'percent' => '10.00'], 'system');
        $log('2026-10-25 03:00:00', 104, 90004, 'applied', ['amount' => '50.00', 'percent' => '5.00'], 'system');
        // The records behind those entries, with the paid invoices and the discount lines still on them (so "Saved in <year>" counts them).
        foreach ([[90001, 101, 2, '10.00', '12000.00', '1200.00', true, '2026-10-03 03:00:00'], [90002, 102, 1, '5.00', '6000.00', '300.00', false, '2026-10-07 03:00:00'], [90003, 101, 2, '10.00', '12000.00', '1200.00', false, '2026-10-20 03:00:00'], [90004, 104, 1, '5.00', '1000.00', '50.00', false, '2026-10-25 03:00:00']] as [$invoice, $client, $tier, $percent, $base, $amount, $dryRun, $at]) {
            $itemId = $dryRun ? null : $invoice * 10;
            $repo->saveInvoiceRecord(['invoiceId' => $invoice, 'line' => 0, 'clientId' => $client, 'itemId' => $itemId, 'taxed' => false, 'tierIndex' => $tier, 'percent' => $percent, 'base' => $base, 'amount' => $amount, 'dryRun' => $dryRun, 'createdAt' => $at]);
            $repo->invoices[$invoice] = ['id' => $invoice, 'clientId' => $client, 'status' => 'Paid', 'dueDate' => substr($at, 0, 10), 'paymentMethod' => 'banktransfer'];
            $repo->invoiceItems[$invoice] = [['id' => $invoice * 10 - 1, 'type' => 'Hosting', 'relId' => $client * 10, 'description' => 'sPremium-1 - site' . $client . '.example', 'amount' => $base, 'taxed' => 0]];
            if ($itemId !== null) {
                $repo->invoiceItems[$invoice][] = ['id' => $itemId, 'type' => 'AgencyDiscount', 'relId' => $client, 'description' => 'Agency discount ' . Money::formatPercent($percent) . '% on 1 qualifying item', 'amount' => '-' . $amount, 'taxed' => 0];
            }
        }
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
