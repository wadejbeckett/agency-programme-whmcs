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
        $repo->settings = $settings;
        Hooks::$factory = static fn (): Module => new Module($repo, Config::fromSettings($settings), new FrozenClock(new \DateTimeImmutable('2026-10-18 02:00:00')), new RecordingActivityLog());
    }

    public function testHooksFileRegistersTheHooks(): void
    {
        require __DIR__ . '/../modules/addons/agencydiscount/hooks.php';
        // AdminHomeWidgets is only registered inside WHMCS, where \WHMCS\Module\AbstractWidget exists
        self::assertSame(['InvoiceCreation', 'PreInvoicingGenerateInvoiceItems', 'DailyCronJob', 'AdminClientProfileTabFields', 'AdminClientProfileTabFieldsSave', 'AdminAreaClientSummaryPage', 'ClientAreaHomepagePanels'], array_column(WhmcsCalls::$hooks, 'hook'));
    }

    public function testClientHomePanelForLoggedInApprovedAgency(): void
    {
        $this->useModule(Fixtures::settings());
        $panels = new \WHMCS\View\Menu\Item();
        Hooks::clientHomePanels($panels);
        self::assertSame([], $panels->children, 'nobody logged in');

        \WHMCS\Session::$values['uid'] = '600';
        Hooks::clientHomePanels($panels);
        self::assertSame([], $panels->children, 'not an agency');

        \WHMCS\Session::$values['uid'] = 131;
        Hooks::clientHomePanels($panels);
        $panel = $panels->children['agencydiscount'] ?? null;
        self::assertNotNull($panel);
        self::assertSame('Your agency programme', $panel['label']);
        self::assertSame('index.php?m=agencydiscount', $panel['extras']['btn-link']);
        self::assertSame('Agency programme', $panel['extras']['btn-text']);
        $body = $panel['bodyHtml'];
        self::assertStringContainsString('<span class="badge badge-success">Tier 1 (5% off)</span>', $body);
        self::assertStringContainsString('ZAR 1,012.92', $body);
        self::assertStringContainsString('ZAR 42.50 off the 2026-10-25 invoice of ZAR 850.00', $body);
        self::assertStringContainsString('Saved in 2026', $body);
        self::assertStringContainsString('ZAR 987.09 more a month reaches tier 2 (10% off)', $body);
        self::assertStringNotContainsString(' I ', $body);
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testClientHomePanelNeverThrows(): void
    {
        \WHMCS\Session::$values['uid'] = 131;
        Hooks::$factory = static function (): Module {
            throw new \RuntimeException('database gone');
        };
        $panels = new \WHMCS\View\Menu\Item();
        Hooks::clientHomePanels($panels);
        self::assertSame([], $panels->children);
        self::assertStringContainsString('RuntimeException: database gone', WhmcsCalls::$activity[0]['message']);
        self::assertSame(131, Hooks::clientId());
        \WHMCS\Session::$values['uid'] = 'abc';
        self::assertNull(Hooks::clientId());
    }

    public function testClientSummaryCardForApprovedAgenciesOnly(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        $this->useModule(Fixtures::settings());
        $card = Hooks::clientSummary(['userid' => 131]);
        self::assertStringContainsString('Agency Programme', $card);
        self::assertStringContainsString('<span class="label label-success">Tier 1 (5%)</span>', $card);
        self::assertStringContainsString('ZAR 1,012.92', $card);
        self::assertStringContainsString('2026-10-25: ZAR 42.50 off ZAR 850.00', $card);
        self::assertStringContainsString('ZAR 987.09 to tier 2 (10%)', $card);
        self::assertStringContainsString('style="width: 1%"', $card);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=131"', $card);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;action=log&amp;client=131"', $card);
        self::assertStringContainsString('href="clientsprofile.php?userid=131"', $card);
        self::assertStringNotContainsString('Dry-run', $card);
        self::assertSame('', Hooks::clientSummary(['userid' => 600]));
        self::assertSame('', Hooks::clientSummary(['userid' => 0]));
        self::assertSame('', Hooks::clientSummary([]));
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testClientSummaryCardOnlyForRolesOnTheAddonAccessList(): void
    {
        $this->useModule(Fixtures::settings());
        self::assertSame('', Hooks::clientSummary(['userid' => 131]), 'no admin in the session');
        \WHMCS\Session::$values['adminid'] = 2;
        self::assertSame('', Hooks::clientSummary(['userid' => 131]), 'admin 2 is in role 3, outside access "1,2"');
        \WHMCS\Session::$values['adminid'] = 1;
        self::assertStringContainsString('Agency Programme', Hooks::clientSummary(['userid' => 131]));
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testClientSummaryCardNotesDryRunAndStartDate(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $this->useModule(['dry_run' => 'on'] + Fixtures::settings());
        $card = Hooks::clientSummary(['userid' => 131]);
        self::assertStringContainsString('Dry-run mode is on', $card);
        self::assertStringContainsString('starts 2027-01-01', $card);
        self::assertStringContainsString('2026-10-25: no discount on ZAR 850.00', $card);
    }

    public function testClientSummaryCardNeverThrows(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        Hooks::$factory = static function (): Module {
            throw new \RuntimeException('database gone');
        };
        self::assertSame('', Hooks::clientSummary(['userid' => 131]));
        self::assertStringContainsString('RuntimeException: database gone', WhmcsCalls::$activity[0]['message']);
        Hooks::reset();
        $this->useModule(Fixtures::settings());
        unset($this->repo->clients[131]);
        self::assertSame('', Hooks::clientSummary(['userid' => 131]));
    }

    public function testProfileSaveRecordsTheSessionAdmin(): void
    {
        \WHMCS\Session::$values['adminid'] = '7';
        $this->useModule(Fixtures::settings());
        Hooks::profileSave(['userid' => 600], ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => '']);
        self::assertSame('admin:7', $this->repo->agency(600)['approvedBy'] ?? null);
        self::assertSame(7, Hooks::adminId());
        \WHMCS\Session::$values['adminid'] = null;
        self::assertNull(Hooks::adminId());
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
