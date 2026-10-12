<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ClientNotFound;
use AgencyDiscount\Config;
use AgencyDiscount\SpendCalculator;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class SpendCalculatorTest extends TestCase
{
    public function testCountsOnlyConfiguredActiveItemsAtMonthlyEquivalent(): void
    {
        $calc = new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings()));
        $report = $calc->forClient(131);
        self::assertSame('1012.92', $report->monthlySpend);
        self::assertSame([1001, 1002, 1003, 1006], $report->serviceIds());
        self::assertSame([51, 52, 53], $report->addonIds());
        self::assertSame(7, $report->countedItems());
        self::assertSame('85.00', $report->services[2]['monthly']);
        self::assertSame('77.92', $report->addons[2]['monthly']);
        self::assertSame(1, $report->tier?->index);
        self::assertSame('5.00', $report->tier?->percent);
        self::assertSame(2, $report->nextTier?->index);
        self::assertSame('987.09', $report->shortfallToNextTier());
        self::assertTrue($report->currencyHasTiers);
        self::assertSame([1001 => 0, 1002 => 0, 1003 => 0, 1006 => 8], $report->servicePromoIds());
        self::assertSame('Agency One', $report->clientName);
        self::assertSame('ZAR', $report->currency);
    }

    public function testTopTierHasNoNextTier(): void
    {
        $calc = new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings()));
        $report = $calc->forClient(404);
        self::assertSame('6000.00', $report->monthlySpend);
        self::assertSame(3, $report->tier?->index);
        self::assertNull($report->nextTier);
        self::assertNull($report->shortfallToNextTier());
    }

    public function testCurrencyWithoutTiers(): void
    {
        $calc = new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings()));
        $report = $calc->forClient(500);
        self::assertSame('300.00', $report->monthlySpend);
        self::assertNull($report->tier);
        self::assertFalse($report->currencyHasTiers);
    }

    public function testStatusesSettingWidensCount(): void
    {
        $calc = new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(['statuses' => 'Active,Terminated'] + Fixtures::settings()));
        $report = $calc->forClient(131);
        self::assertContains(1004, $report->serviceIds());
        self::assertContains(55, $report->addonIds());
        self::assertSame('1182.92', $report->monthlySpend);
    }

    public function testPendingItemsAreListedSeparatelyAndNotCounted(): void
    {
        $repo = Fixtures::agencyRepo();
        $repo->services[1007] = ['clientId' => 131, 'id' => 1007, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'g.example', 'amount' => '1000.00', 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18', 'promoId' => 0];
        // a pending service of a product that does not count
        $repo->services[1008] = ['clientId' => 131, 'id' => 1008, 'productId' => 120, 'groupId' => 15, 'productName' => 'DNS Only', 'domain' => 'h.example', 'amount' => '68.00', 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18', 'promoId' => 0];
        $repo->addons[56] = ['clientId' => 131, 'id' => 56, 'hostingId' => 1007, 'addonId' => 12, 'addonName' => 'Advanced Email Security', 'domain' => 'g.example', 'recurring' => '1020.00', 'billingCycle' => 'Annually', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18'];
        $repo->addons[57] = ['clientId' => 131, 'id' => 57, 'hostingId' => 1007, 'addonId' => 99, 'addonName' => 'Other addon', 'domain' => 'g.example', 'recurring' => '50.00', 'billingCycle' => 'Monthly', 'status' => 'Pending', 'nextInvoiceDate' => '2026-10-18'];

        $report = (new SpendCalculator($repo, Config::fromSettings(Fixtures::settings())))->forClient(131);
        self::assertSame('1012.92', $report->monthlySpend, 'pending items do not count toward spend');
        self::assertSame([1001, 1002, 1003, 1006], $report->serviceIds());
        self::assertSame([51, 52, 53], $report->addonIds());
        self::assertSame(7, $report->countedItems());
        self::assertSame(1, $report->tier?->index);
        self::assertSame([1007 => '1000.00'], $report->pendingServiceMonthly());
        self::assertSame([56 => '85.00'], $report->pendingAddonMonthly());

        // with Pending in the counted statuses the items take the ordinary route and are never listed twice
        $report = (new SpendCalculator($repo, Config::fromSettings(['statuses' => 'Active,Pending'] + Fixtures::settings())))->forClient(131);
        self::assertSame('2097.92', $report->monthlySpend);
        self::assertSame([1001, 1002, 1003, 1006, 1007], $report->serviceIds());
        self::assertSame([51, 52, 53, 56], $report->addonIds());
        self::assertSame([], $report->pendingServiceMonthly());
        self::assertSame([], $report->pendingAddonMonthly());
    }

    public function testUnknownClient(): void
    {
        $this->expectException(ClientNotFound::class);
        (new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings())))->forClient(1);
    }
}
