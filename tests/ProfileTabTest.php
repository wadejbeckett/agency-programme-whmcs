<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\ProfileTab;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ProfileTabTest extends TestCase
{
    private ArrayRepository $repo;
    private RecordingActivityLog $activity;
    private ProfileTab $tab;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        $this->activity = new RecordingActivityLog();
        $module = new Module($this->repo, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), $this->activity);
        $this->tab = new ProfileTab($module);
    }

    public function testApproveStoresRecalculatesAndLogs(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'Studio contact'], 'admin:1');
        $row = $this->repo->agency(131);
        self::assertTrue($row['approved'] ?? false);
        self::assertSame('admin:1', $row['approvedBy'] ?? null);
        self::assertSame('2026-10-12 09:00:00', $row['approvedAt'] ?? null);
        self::assertSame('Studio contact', $row['note'] ?? null);
        self::assertSame(1, $row['tierIndex'] ?? null);
        self::assertSame(['tier_changed', 'approved'], array_column($this->repo->logEntries(131, 10), 'event'));
        self::assertStringContainsString('approved as agency', $this->activity->entries[0]['message']);
        $html = $this->tab->fields(131)['Agency discount'];
        self::assertStringContainsString('tier 1 (5%), 7 items, recalculated 2026-10-12 09:00:00', $html);
        self::assertStringContainsString('Approved by admin:1 on 2026-10-12 09:00:00', $html);
        foreach (['agencydiscount_present', 'agencydiscount_approved', 'agencydiscount_note', 'agencydiscount_effective_from'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $html);
        }
        // the approval only: no live figures beside it (they are on the summary card and the detail page)
        self::assertStringNotContainsString('Right now', $html);
        self::assertStringNotContainsString('progress-bar', $html);
        self::assertStringNotContainsString('class="row"', $html);
        self::assertStringNotContainsString('action=agency', $html);
        self::assertStringContainsString('Leave empty to start immediately', $html);
    }

    public function testUnapprovedClient(): void
    {
        $html = $this->tab->fields(600)['Agency discount'];
        self::assertStringContainsString('Not an approved agency', $html);
        self::assertStringNotContainsString('Approved by', $html);
    }

    public function testMissingClientFallsBackToTheStoredSummary(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => ''], 'admin:1');
        unset($this->repo->clients[131]);
        $html = $this->tab->fields(131)['Agency discount'];
        self::assertStringContainsString('tier 1 (5%), 7 items', $html);
    }

    public function testARecalculationSavedDuringAProfileSaveSurvivesIt(): void
    {
        // the daily cron writes new figures after the profile form read the row and before its save
        $racing = new class (Fixtures::data()) extends ArrayRepository {
            public function agency(int $clientId): ?array
            {
                $row = parent::agency($clientId);
                if ($row !== null) {
                    $this->saveCalculation($clientId, ['currency' => 'ZAR', 'spend' => '9999.00', 'tierIndex' => 3, 'tierPercent' => '15.00', 'countedServices' => 9, 'countedAddons' => 9, 'calculatedAt' => '2026-10-12 08:59:59', 'updatedAt' => '2026-10-12 08:59:59']);
                }
                return $row;
            }
        };
        $racing->saveAgency(['clientId' => 131, 'approved' => true, 'note' => 'old', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        $module = new Module($racing, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), $this->activity);
        (new ProfileTab($module))->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'new note', 'agencydiscount_effective_from' => '2027-01-01'], 'admin:2');
        $row = $racing->agencies[131];
        self::assertSame(['new note', '2027-01-01', true, 'admin:1'], [$row['note'], $row['effectiveFrom'], $row['approved'], $row['approvedBy']], 'the approval fields are saved');
        self::assertSame(['9999.00', 3, '15.00', 9, '2026-10-12 08:59:59'], [$row['spend'], $row['tierIndex'], $row['tierPercent'], $row['countedServices'], $row['calculatedAt']], 'the recalculation is not undone');
        self::assertSame('2026-10-12 09:00:00', $row['updatedAt']);

        // a first approval of a client without a row inserts one, and the approval's own recalculation then fills the figures
        (new ProfileTab($module))->save(404, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => ''], 'admin:2');
        self::assertSame(['admin:2', true, '2026-10-12 09:00:00'], [$racing->agencies[404]['approvedBy'], $racing->agencies[404]['approved'], $racing->agencies[404]['calculatedAt']]);
        self::assertNotSame('0.00', $racing->agencies[404]['spend']);
    }

    public function testRevokeAndNoteChange(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'a'], 'admin:1');
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'b'], 'admin:1');
        self::assertSame('note_changed', $this->repo->logEntries(131, 1)[0]['event']);
        self::assertSame(['from' => 'a', 'to' => 'b'], $this->repo->logEntries(131, 1)[0]['detail']);
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_note' => 'b'], 'admin:2');
        self::assertFalse($this->repo->agency(131)['approved'] ?? true);
        self::assertSame('revoked', $this->repo->logEntries(131, 1)[0]['event']);
        self::assertSame('admin:2', $this->repo->logEntries(131, 1)[0]['actor']);
        self::assertStringContainsString('Not an approved agency', $this->tab->fields(131)['Agency discount']);
    }

    public function testNoChangeWritesNothing(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_note' => ''], 'admin:1');
        self::assertNull($this->repo->agency(131));
        self::assertSame([], $this->repo->logEntries(131, 10));
    }

    public function testNoteIsTruncatedTo255(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => str_repeat('x', 300)], 'admin:1');
        self::assertSame(255, strlen($this->repo->agency(131)['note'] ?? ''));
    }

    public function testEffectiveFromRoundTripAndLog(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => '', 'agencydiscount_effective_from' => '2027-01-01'], 'admin:1');
        self::assertSame('2027-01-01', $this->repo->agency(131)['effectiveFrom'] ?? null);
        $changed = $this->events('effective_from_changed');
        self::assertCount(1, $changed);
        self::assertSame(['from' => null, 'to' => '2027-01-01'], $changed[0]['detail']);
        $html = $this->tab->fields(131)['Agency discount'];
        self::assertStringContainsString('starts 2027-01-01', $html);
        self::assertStringContainsString('name="agencydiscount_effective_from"', $html);
        self::assertStringContainsString('value="2027-01-01"', $html);

        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => '', 'agencydiscount_effective_from' => ''], 'admin:1');
        $row = $this->repo->agency(131);
        self::assertNotNull($row);
        self::assertNull($row['effectiveFrom']);
        $changed = $this->events('effective_from_changed');
        self::assertCount(2, $changed);
        self::assertSame(['from' => '2027-01-01', 'to' => null], $changed[0]['detail']);
        self::assertStringNotContainsString('starts 2', $this->tab->fields(131)['Agency discount']);
    }

    public function testInvalidEffectiveFromIsIgnored(): void
    {
        $this->tab->save(131, ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => '', 'agencydiscount_effective_from' => '2027-13-40'], 'admin:1');
        $row = $this->repo->agency(131);
        self::assertNotNull($row);
        self::assertTrue($row['approved']);
        self::assertNull($row['effectiveFrom']);
        $invalid = $this->events('effective_from_invalid');
        self::assertCount(1, $invalid);
        self::assertSame(['value' => '2027-13-40'], $invalid[0]['detail']);
        self::assertSame([], $this->events('effective_from_changed'));
    }

    /** @return list<array{id:int, clientId:?int, invoiceId:?int, event:string, detail:array<string,mixed>, actor:string, createdAt:string}> newest first */
    private function events(string $event): array
    {
        return array_values(array_filter($this->repo->logEntries(131, 100), static fn (array $e): bool => $e['event'] === $event));
    }
}
