<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\Preview;
use AgencyDiscount\Config;
use AgencyDiscount\SpendCalculator;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PreviewTest extends TestCase
{
    public function testGroupsByNextInvoiceDateWithinWindow(): void
    {
        $report = (new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings())))->forClient(131);
        $rows = Preview::upcoming($report, new \DateTimeImmutable('2026-10-12'), 35);
        // 2026-10-25: services 1001, 1002 and promoted 1006 (an estimate: the invoice decides about promotions), add-ons 51 and 52
        self::assertSame([
            ['date' => '2026-10-25', 'base' => '850.00', 'percent' => '5.00', 'discount' => '42.50', 'items' => 5],
            ['date' => '2026-11-03', 'base' => '1955.00', 'percent' => '5.00', 'discount' => '97.75', 'items' => 2],
        ], $rows);
        self::assertSame([['date' => '2026-10-25', 'base' => '850.00', 'percent' => '5.00', 'discount' => '42.50', 'items' => 5]], Preview::upcoming($report, new \DateTimeImmutable('2026-10-12'), 20));
    }

    public function testNegativeAmountIsLeftOutLikeOnTheInvoice(): void
    {
        $repo = Fixtures::agencyRepo();
        $repo->services[1007] = ['clientId' => 131, 'id' => 1007, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'g.example', 'amount' => '-10.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0];
        $report = (new SpendCalculator($repo, Config::fromSettings(Fixtures::settings())))->forClient(131);
        // InvoiceDiscounter never discounts a negative line, so the estimate leaves it out too
        self::assertSame(
            ['date' => '2026-10-25', 'base' => '850.00', 'percent' => '5.00', 'discount' => '42.50', 'items' => 5],
            Preview::upcoming($report, new \DateTimeImmutable('2026-10-12'), 35)[0],
        );
    }

    public function testRowsBeforeStartDateGetNoDiscount(): void
    {
        $report = (new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings())))->forClient(131);
        self::assertSame([
            ['date' => '2026-10-25', 'base' => '850.00', 'percent' => null, 'discount' => '0.00', 'items' => 5],
            ['date' => '2026-11-03', 'base' => '1955.00', 'percent' => '5.00', 'discount' => '97.75', 'items' => 2],
        ], Preview::upcoming($report, new \DateTimeImmutable('2026-10-12'), 35, '2026-11-01'));
        $onTheDay = Preview::upcoming($report, new \DateTimeImmutable('2026-10-12'), 35, '2026-10-25 00:00:00');
        self::assertSame(['5.00', '5.00'], array_column($onTheDay, 'percent'));
    }

    public function testWindowIsInclusiveAtBothEnds(): void
    {
        $report = (new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings())))->forClient(131);
        $dates = static fn (string $from, int $days): array => array_column(Preview::upcoming($report, new \DateTimeImmutable($from), $days), 'date');
        self::assertSame(['2026-10-25', '2026-11-03'], $dates('2026-10-25', 9));
        self::assertSame(['2026-10-25'], $dates('2026-10-25', 8));
        self::assertSame(['2026-11-03'], $dates('2026-10-26', 8));
        self::assertSame([], $dates('2026-10-26', 7));
    }

    public function testNoTierGivesZeroDiscount(): void
    {
        $report = (new SpendCalculator(Fixtures::agencyRepo(), Config::fromSettings(Fixtures::settings())))->forClient(500);
        self::assertSame([['date' => '2026-10-20', 'base' => '300.00', 'percent' => null, 'discount' => '0.00', 'items' => 1]], Preview::upcoming($report, new \DateTimeImmutable('2026-10-12')));
    }

    public function testItemsWithoutNextInvoiceDateAreIgnored(): void
    {
        $repo = Fixtures::agencyRepo();
        $repo->services[1001]['nextInvoiceDate'] = null;
        $report = (new SpendCalculator($repo, Config::fromSettings(Fixtures::settings())))->forClient(131);
        $rows = Preview::upcoming($report, new \DateTimeImmutable('2026-10-12'), 20);
        self::assertSame([['date' => '2026-10-25', 'base' => '680.00', 'percent' => '5.00', 'discount' => '34.00', 'items' => 4]], $rows);
    }
}
