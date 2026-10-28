<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Recalculator;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class RecalculatorTest extends TestCase
{
    private ArrayRepository $repo;
    private RecordingActivityLog $activity;
    private Recalculator $recalc;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        $this->activity = new RecordingActivityLog();
        $this->recalc = new Recalculator($this->repo, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), $this->activity);
        foreach ([131, 404, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'effectiveFrom' => $id === 131 ? '2027-01-01' : null, 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    public function testStoresSpendAndLogsFirstTier(): void
    {
        $report = $this->recalc->recalculate(131, 'test');
        $row = $this->repo->agency(131);
        self::assertSame('1012.92', $row['spend'] ?? null);
        self::assertSame(1, $row['tierIndex'] ?? null);
        self::assertSame('5.00', $row['tierPercent'] ?? null);
        self::assertSame(4, $row['countedServices'] ?? null);
        self::assertSame(3, $row['countedAddons'] ?? null);
        self::assertSame('2026-10-12 09:00:00', $row['calculatedAt'] ?? null);
        self::assertTrue($row['approved'] ?? false);
        self::assertSame('ZAR', $row['currency'] ?? null);
        self::assertSame('2027-01-01', $row['effectiveFrom'] ?? null);
        self::assertSame(1, $report->tier?->index);
        $log = $this->repo->logEntries(131, 10);
        self::assertCount(1, $log);
        self::assertSame('tier_changed', $log[0]['event']);
        self::assertSame(['from' => null, 'to' => 1, 'fromPercent' => null, 'toPercent' => '5.00', 'spend' => '1012.92', 'currency' => 'ZAR'], $log[0]['detail']);
        self::assertSame('test', $log[0]['actor']);
        self::assertCount(1, $this->activity->entries);
        self::assertStringContainsString('tier none to tier 1 (5%)', $this->activity->entries[0]['message']);
        self::assertSame(131, $this->activity->entries[0]['clientId']);
    }

    public function testUnchangedTierLogsNothing(): void
    {
        $this->recalc->recalculate(131, 'test');
        $this->recalc->recalculate(131, 'test');
        self::assertCount(1, $this->repo->logEntries(131, 10));
        self::assertCount(1, $this->activity->entries);
    }

    public function testTierDropIsLogged(): void
    {
        $this->recalc->recalculate(404, 'test');
        $this->repo->services[2001]['status'] = 'Suspended';
        $this->recalc->recalculate(404, 'cron');
        $log = $this->repo->logEntries(404, 10);
        self::assertSame('tier_changed', $log[0]['event']);
        self::assertSame(3, $log[0]['detail']['from']);
        self::assertNull($log[0]['detail']['to']);
        self::assertSame('0.00', $this->repo->agency(404)['spend'] ?? null);
    }

    public function testCurrencyWithoutTiersIsLoggedOnce(): void
    {
        $this->recalc->recalculate(500, 'test');
        $this->recalc->recalculate(500, 'test');
        $log = $this->repo->logEntries(500, 10);
        self::assertCount(1, $log);
        self::assertSame('no_tier', $log[0]['event']);
        self::assertSame(['currency' => 'EUR', 'spend' => '300.00'], $log[0]['detail']);
    }

    public function testNonApprovedClientIsComputedButNotStored(): void
    {
        $report = $this->recalc->recalculate(600, 'test');
        self::assertSame('170.00', $report->monthlySpend);
        self::assertNull($this->repo->agency(600));
        self::assertSame([], $this->repo->logEntries(600, 10));
    }

    public function testRecalculateAllCoversApprovedAgenciesAndSkipsMissingClients(): void
    {
        $this->repo->saveAgency(['clientId' => 77, 'approved' => true, 'note' => '', 'approvedBy' => 'x', 'approvedAt' => null, 'effectiveFrom' => null, 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
        $reports = $this->recalc->recalculateAll('cron:daily');
        self::assertSame([131, 404, 500], array_map(static fn ($r) => $r->clientId, $reports));
        self::assertSame('client_missing', $this->repo->logEntries(77, 1)[0]['event']);
    }

    public function testRecalculateAllContinuesPastAFailingAgency(): void
    {
        $failing = new class (Fixtures::data()) extends ArrayRepository {
            public function client(int $clientId): ?array
            {
                if ($clientId === 404) {
                    throw new \RuntimeException('database went away');
                }
                return parent::client($clientId);
            }
        };
        $failing->agencies = $this->repo->agencies;
        $recalc = new Recalculator($failing, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), $this->activity);
        $reports = $recalc->recalculateAll('cron:daily');
        self::assertSame([131, 500], array_map(static fn ($r) => $r->clientId, $reports));
        $log = $failing->logEntries(404, 1)[0];
        self::assertSame('recalculate_failed', $log['event']);
        self::assertSame(['message' => 'database went away'], $log['detail']);
        self::assertSame('cron:daily', $log['actor']);
        self::assertSame('2026-10-12 09:00:00', $failing->agency(500)['calculatedAt'] ?? null);
    }

    public function testRevokeSavedDuringARecalculationSurvivesIt(): void
    {
        // an admin revokes 131 and edits its note and start date after the recalculation read the row
        $racing = new class (Fixtures::data()) extends ArrayRepository {
            public function agency(int $clientId): ?array
            {
                $row = parent::agency($clientId);
                if ($row !== null) {
                    $this->agencies[$clientId] = ['approved' => false, 'note' => 'revoked', 'effectiveFrom' => '2027-06-01', 'approvedBy' => 'admin:9', 'updatedAt' => '2026-10-12 08:59:59'] + $row;
                }
                return $row;
            }
        };
        $racing->agencies = $this->repo->agencies;
        $recalc = new Recalculator($racing, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), $this->activity);
        $recalc->recalculate(131, 'cron:daily');
        $row = $racing->agencies[131];
        self::assertFalse($row['approved'], 'the revoke is not undone');
        self::assertSame('revoked', $row['note']);
        self::assertSame('2027-06-01', $row['effectiveFrom']);
        self::assertSame('admin:9', $row['approvedBy']);
        self::assertSame('2026-10-01 00:00:00', $row['approvedAt']);
        self::assertSame('1012.92', $row['spend'], 'the computed columns are still written');
        self::assertSame(1, $row['tierIndex']);
        self::assertSame('2026-10-12 09:00:00', $row['calculatedAt']);
    }

    public function testCalculationForAnUnknownAgencyWritesNothing(): void
    {
        $this->repo->saveCalculation(999, ['currency' => 'ZAR', 'spend' => '1.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => '2026-10-12 09:00:00', 'updatedAt' => '2026-10-12 09:00:00']);
        self::assertNull($this->repo->agency(999));
    }
}
