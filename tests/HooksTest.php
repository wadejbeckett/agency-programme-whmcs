<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Hooks;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use AgencyDiscount\Tests\Support\WhmcsCalls;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    private ArrayRepository $repo;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        Hooks::reset();
        $this->repo = Fixtures::agencyRepo();
        $this->repo->saveAgency(['clientId' => 131, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'effectiveFrom' => null, 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        Hooks::reset();
    }

    /** @param array<string,string> $settings */
    private function useModule(array $settings): void
    {
        $repo = $this->repo;
        Hooks::$factory = static fn (): Module => new Module($repo, Config::fromSettings($settings), new FrozenClock(new \DateTimeImmutable('2026-10-18 02:00:00')), new RecordingActivityLog());
    }

    public function testHooksFileRegistersFiveHooks(): void
    {
        require __DIR__ . '/../modules/addons/agencydiscount/hooks.php';
        self::assertSame(['InvoiceCreation', 'PreInvoicingGenerateInvoiceItems', 'DailyCronJob', 'AdminClientProfileTabFields', 'AdminClientProfileTabFieldsSave'], array_column(WhmcsCalls::$hooks, 'hook'));
    }

    public function testInvoiceCreationAppliesDiscount(): void
    {
        $this->useModule(Fixtures::settings());
        Hooks::invoiceCreation(['invoiceid' => 9001, 'source' => 'autogen', 'user' => 'system', 'status' => 'Unpaid']);
        self::assertCount(9, $this->repo->invoiceItems(9001));
        self::assertSame('system', $this->repo->logEntries(131, 1)[0]['actor']);
    }

    public function testActorMapping(): void
    {
        self::assertSame('system', Hooks::actorFrom('system'));
        self::assertSame('admin:3', Hooks::actorFrom(3));
        self::assertSame('admin:3', Hooks::actorFrom('3'));
        self::assertSame('api', Hooks::actorFrom('api'));
        self::assertSame('system', Hooks::actorFrom(null));
    }

    public function testConfigProblemIsLoggedAndInvoiceIsLeftAlone(): void
    {
        $this->useModule(['tiers' => '{'] + Fixtures::settings());
        Hooks::invoiceCreation(['invoiceid' => 9001, 'source' => 'autogen', 'user' => 'system', 'status' => 'Unpaid']);
        Hooks::invoiceCreation(['invoiceid' => 9001, 'source' => 'autogen', 'user' => 'system', 'status' => 'Unpaid']);
        self::assertCount(8, $this->repo->invoiceItems(9001));
        self::assertCount(1, WhmcsCalls::$activity);
        self::assertStringContainsString('configuration problems: tiers: invalid JSON', WhmcsCalls::$activity[0]['message']);
    }

    public function testUnexpectedExceptionIsSwallowedAndLogged(): void
    {
        Hooks::$factory = static function (): Module {
            throw new \RuntimeException('database gone');
        };
        Hooks::invoiceCreation(['invoiceid' => 9001, 'source' => 'autogen', 'user' => 'system']);
        self::assertStringContainsString('RuntimeException: database gone', WhmcsCalls::$activity[0]['message']);
    }

    public function testRecalculateAllStoresTiers(): void
    {
        $this->useModule(Fixtures::settings());
        Hooks::recalculateAll('cron:daily');
        self::assertSame(1, $this->repo->agency(131)['tierIndex'] ?? null);
        self::assertSame('cron:daily', $this->repo->logEntries(131, 1)[0]['actor']);
    }

    public function testProfileFieldsAndSaveRoundTrip(): void
    {
        $this->useModule(Fixtures::settings());
        $fields = Hooks::profileFields(['userid' => 600]);
        self::assertArrayHasKey('Agency discount', $fields);
        self::assertStringContainsString('name="agencydiscount_approved"', $fields['Agency discount']);
        self::assertStringNotContainsString('checked', $fields['Agency discount']);
        Hooks::profileSave(['userid' => 600], ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'Studio <b>']);
        self::assertTrue($this->repo->agency(600)['approved'] ?? false);
        self::assertStringContainsString('checked', Hooks::profileFields(['userid' => 600])['Agency discount']);
        self::assertStringContainsString('Studio &lt;b&gt;', Hooks::profileFields(['userid' => 600])['Agency discount']);
    }

    public function testProfileSaveDecodesWhmcsSanitisedInput(): void
    {
        $this->useModule(Fixtures::settings());
        $request = ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'A &amp; B&#039;s'];
        Hooks::profileSave(['userid' => 600], $request);
        self::assertSame('A & B\'s', $this->repo->agency(600)['note'] ?? null);
        Hooks::profileSave(['userid' => 600], $request);
        self::assertSame(['approved'], array_column($this->repo->logEntries(600, 10), 'event'));
    }

    public function testProfileSaveIgnoresRequestsWithoutMarker(): void
    {
        $this->useModule(Fixtures::settings());
        Hooks::profileSave(['userid' => 131], ['firstname' => 'x']);
        self::assertTrue($this->repo->agency(131)['approved'] ?? false);
    }
}
