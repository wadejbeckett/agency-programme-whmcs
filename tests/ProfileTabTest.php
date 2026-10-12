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
        self::assertStringContainsString('tier 1 (5%), 7 items, recalculated 2026-10-12 09:00:00', $this->tab->fields(131)['Agency discount']);
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
