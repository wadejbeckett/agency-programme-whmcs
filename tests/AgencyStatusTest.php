<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\AgencyStatus;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class AgencyStatusTest extends TestCase
{
    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        foreach ([131, 404, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    private function module(): Module
    {
        return new Module($this->repo, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
    }

    public function testPositionInTheBandAndTheNextInvoice(): void
    {
        $status = AgencyStatus::of($this->module(), 131);
        self::assertNotNull($status);
        self::assertSame('1012.92', $status->report->monthlySpend);
        self::assertSame(1, $status->report->tier?->index);
        self::assertSame('987.09', $status->shortfall());
        self::assertSame(1, $status->progressPercent());
        self::assertFalse($status->isNearNextTier());
        self::assertFalse($status->isWaiting());
        self::assertNull($status->startsOn());
        self::assertSame(['date' => '2026-10-25', 'base' => '850.00', 'percent' => '5.00', 'discount' => '42.50', 'items' => 5], $status->nextInvoice());
        self::assertSame('140.25', $status->discountInWindow());
    }

    public function testTopTierAndCurrencyWithoutTiers(): void
    {
        $top = AgencyStatus::of($this->module(), 404);
        self::assertNotNull($top);
        self::assertSame(3, $top->report->tier?->index);
        self::assertNull($top->shortfall());
        self::assertSame(100, $top->progressPercent());
        self::assertFalse($top->isNearNextTier());

        $euro = AgencyStatus::of($this->module(), 500);
        self::assertNotNull($euro);
        self::assertFalse($euro->report->currencyHasTiers);
        self::assertNull($euro->progressPercent());
        self::assertNull($euro->shortfall());
        self::assertFalse($euro->isNearNextTier());
        self::assertSame('0.00', $euro->discountInWindow());
    }

    public function testNearTheNextThreshold(): void
    {
        $this->repo->services[2001]['amount'] = '2950.00';
        $status = AgencyStatus::of($this->module(), 404);
        self::assertNotNull($status);
        self::assertSame(2, $status->report->tier?->index);
        self::assertSame('50.01', $status->shortfall());
        self::assertSame(95, $status->progressPercent());
        self::assertTrue($status->isNearNextTier());
        self::assertSame('5', AgencyStatus::NEAR_TIER_PERCENT);
    }

    public function testBelowTheFirstTier(): void
    {
        $this->repo->services[2001]['amount'] = '400.00';
        $status = AgencyStatus::of($this->module(), 404);
        self::assertNotNull($status);
        self::assertNull($status->report->tier);
        self::assertSame('600.01', $status->shortfall());
        self::assertSame(40, $status->progressPercent());
    }

    public function testStartDateInTheFutureMeansWaiting(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $status = AgencyStatus::of($this->module(), 131);
        self::assertNotNull($status);
        self::assertTrue($status->isWaiting());
        self::assertSame('2027-01-01', $status->startsOn());
        self::assertSame('0.00', $status->discountInWindow());
        self::assertSame('0.00', $status->nextInvoice()['discount'] ?? null);

        $this->repo->agencies[131]['effectiveFrom'] = '2026-01-01';
        $status = AgencyStatus::of($this->module(), 131);
        self::assertNotNull($status);
        self::assertFalse($status->isWaiting());
        self::assertNull($status->startsOn());
        self::assertSame('140.25', $status->discountInWindow());
    }

    public function testNotApprovedIsNullAndAllSkipsMissingClients(): void
    {
        self::assertNull(AgencyStatus::of($this->module(), 600));
        $this->repo->saveAgency(['clientId' => 777, 'approved' => true, 'updatedAt' => '2026-10-01 00:00:00']);
        $missing = [];
        $all = AgencyStatus::all($this->module(), $missing);
        self::assertSame([131, 404, 500], array_map(static fn (AgencyStatus $s): int => $s->report->clientId, $all));
        self::assertSame([777], $missing);
    }
}
