<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Hooks;
use AgencyDiscount\LogEntry;
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

    public function testDailyCronReportsEngineRowsWithNoStoredValueOnce(): void
    {
        $settings = Fixtures::settings();
        unset($settings['tiers'], $settings['dry_run']);
        $this->useModule($settings);
        Hooks::reportDefaults();
        self::assertSame([['message' => 'Agency Discount: no stored value for tiers, dry_run; the defaults apply (no tiers, dry-run on) until the Settings tab is saved', 'clientId' => 0]], WhmcsCalls::$activity);

        WhmcsCalls::reset();
        Hooks::reset();
        $this->useModule(Fixtures::settings());
        Hooks::reportDefaults();
        self::assertSame([], WhmcsCalls::$activity);
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

    public function testClientHomePanelOnlyForAUserWhoMaySeeTheBilling(): void
    {
        $this->useModule(Fixtures::settings());
        \WHMCS\Session::$values['uid'] = 131;
        $panel = static function (): \WHMCS\View\Menu\Item {
            $panels = new \WHMCS\View\Menu\Item();
            Hooks::clientHomePanels($panels);
            return $panels;
        };
        self::assertArrayHasKey('agencydiscount', $panel()->children, 'the owner');
        self::assertSame(['clientId' => 131, 'permitted' => true], Hooks::viewer());

        // an invited user with the profile and tickets permissions only
        \WHMCS\Authentication\CurrentUser::$owner = false;
        \WHMCS\Authentication\CurrentUser::$permissions = ['profile', 'tickets'];
        self::assertSame([], $panel()->children, 'no panel for a user without the invoices permission');
        self::assertSame(['clientId' => 131, 'permitted' => false], Hooks::viewer());
        self::assertSame([], WhmcsCalls::$activity, 'nothing is reported: it is not a failure');

        // the same user given the invoices permission
        \WHMCS\Authentication\CurrentUser::$permissions = ['profile', 'tickets', 'invoices'];
        self::assertArrayHasKey('agencydiscount', $panel()->children);

        // an admin logged in as the owner
        \WHMCS\Authentication\CurrentUser::$permissions = [];
        \WHMCS\Authentication\CurrentUser::$masquerading = true;
        self::assertArrayHasKey('agencydiscount', $panel()->children);
        \WHMCS\Authentication\CurrentUser::$masquerading = false;

        // WHMCS's authenticated client is not the session's client: nothing is shown
        \WHMCS\Authentication\CurrentUser::$owner = true;
        \WHMCS\Authentication\CurrentUser::$clientId = 600;
        self::assertSame([], $panel()->children);
        self::assertSame(['clientId' => 131, 'permitted' => false], Hooks::viewer());

        // nobody logged in
        \WHMCS\Authentication\CurrentUser::reset();
        \WHMCS\Authentication\CurrentUser::$loggedOut = true;
        self::assertSame([], $panel()->children);
        \WHMCS\Session::$values['uid'] = null;
        self::assertNull(Hooks::viewer());
        self::assertSame([], WhmcsCalls::$activity, 'none of the denied cases is reported as a failure');
    }

    public function testTheGateMatchesThisClientAmongTheUsersAccountsAndFailsClosedOnAnythingElse(): void
    {
        $this->useModule(Fixtures::settings());
        \WHMCS\Session::$values['uid'] = 131;
        \WHMCS\Authentication\CurrentUser::$owner = false;
        \WHMCS\Authentication\CurrentUser::$permissions = ['profile', 'tickets'];
        $permitted = static fn (): bool => Hooks::viewer()['permitted'] ?? false;

        // an agency's staff member holding the invoices permission on another account (200) only
        \WHMCS\Authentication\CurrentUser::$otherClients = [200 => ['invoices', 'tickets'], 300 => ['tickets']];
        self::assertFalse($permitted(), 'the permission on another account does not open this one');
        $panels = new \WHMCS\View\Menu\Item();
        Hooks::clientHomePanels($panels);
        self::assertSame([], $panels->children);
        // ...and once this account grants it too
        \WHMCS\Authentication\CurrentUser::$permissions = ['invoices'];
        self::assertTrue($permitted());
        \WHMCS\Authentication\CurrentUser::$permissions = ['profile', 'tickets'];

        // the shapes an answer may take: [rows, expected, why]
        $cases = [
            [[['id' => '200'], ['id' => '131']], true, 'several rows, ids as strings, this client among them'],
            [[['id' => 200]], false, 'only another client'],
            [[['id' => 131, 'client_id' => 200]], false, 'a link row: its client_id names the client, not its own id'],
            [[['id' => 7, 'client_id' => '131']], true, 'a link row for this client'],
            [[(object) ['id' => 131]], true, 'a model of this client'],
            [[(object) ['id' => 131, 'client_id' => 200]], false, 'a link model for another client'],
            [[131], false, 'a bare id is not trusted'],
            [[['id' => 'abc']], false, 'a non-numeric id'],
            [[], false, 'no client'],
        ];
        foreach ($cases as [$rows, $expected, $why]) {
            \WHMCS\Authentication\CurrentUser::$rows = $rows;
            self::assertSame($expected, $permitted(), $why);
        }
        \WHMCS\Authentication\CurrentUser::$rows = null;
        self::assertSame([], WhmcsCalls::$activity);

        // WHMCS throws: no panel, and one activity line for the one failed page view
        \WHMCS\Authentication\CurrentUser::$throws = new \RuntimeException('boom');
        $panels = new \WHMCS\View\Menu\Item();
        Hooks::clientHomePanels($panels);
        self::assertSame([], $panels->children);
        self::assertCount(1, WhmcsCalls::$activity);
        self::assertStringContainsString('RuntimeException: boom', WhmcsCalls::$activity[0]['message']);
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
        // exactly: the tier, the spend and one link to the detail page; the rest lives there
        self::assertStringContainsString('<tr><th>Status</th><td><span class="label label-success">Tier 1 (5%)</span></td></tr><tr><th>Qualifying monthly spend</th><td>ZAR 1,012.92</td></tr></tbody></table><p><a href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=131">Agency detail</a></p></div></div>', $card);
        self::assertStringNotContainsString('2026-10-25', $card);
        self::assertStringNotContainsString('progress', $card);
        self::assertStringNotContainsString('action=log', $card);
        self::assertStringNotContainsString('clientsprofile.php', $card);
        self::assertStringNotContainsString('<style>', $card);
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
        self::assertStringContainsString('<p class="text-warning">Dry-run mode is on: the discount is calculated and logged, but not yet added to invoices.</p><p class="text-warning">Approved; the discount starts on 2027-01-01.</p>', $card);
        self::assertStringContainsString('<span class="text-warning">starts 2027-01-01</span>', $card);
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
        $this->repo->admins[7] = 2;
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
        \WHMCS\Session::$values['adminid'] = 1;
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
        \WHMCS\Session::$values['adminid'] = 1;
        $this->useModule(Fixtures::settings());
        $request = ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'A &amp; B&#039;s'];
        Hooks::profileSave(['userid' => 600], $request);
        self::assertSame('A & B\'s', $this->repo->agency(600)['note'] ?? null);
        Hooks::profileSave(['userid' => 600], $request);
        self::assertSame(['approved'], array_column($this->repo->logEntries(600, 10), 'event'));
    }

    public function testProfileFieldsOnlyForRolesOnTheAddonAccessList(): void
    {
        $this->useModule(Fixtures::settings());
        self::assertSame([], Hooks::profileFields(['userid' => 131]), 'no admin in the session');
        \WHMCS\Session::$values['adminid'] = 2;
        self::assertSame([], Hooks::profileFields(['userid' => 131]), 'admin 2 is in role 3, outside access "1,2"');
        \WHMCS\Session::$values['adminid'] = 9;
        self::assertSame([], Hooks::profileFields(['userid' => 131]), 'admin 9 does not exist');
        \WHMCS\Session::$values['adminid'] = 1;
        self::assertArrayHasKey('Agency discount', Hooks::profileFields(['userid' => 131]));
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testProfileSaveFromARoleOutsideTheAccessListIsIgnored(): void
    {
        $this->useModule(Fixtures::settings());
        $approve = ['agencydiscount_present' => '1', 'agencydiscount_approved' => '1', 'agencydiscount_note' => 'forged'];
        \WHMCS\Session::$values['adminid'] = 2;
        Hooks::profileSave(['userid' => 600], $approve);
        self::assertNull($this->repo->agency(600), 'nothing is stored for the client');
        Hooks::profileSave(['userid' => 131], ['agencydiscount_present' => '1', 'agencydiscount_note' => '']);
        self::assertTrue($this->repo->agency(131)['approved'] ?? false, 'an approval cannot be revoked either');
        self::assertSame([], $this->repo->logEntries(null, 10));
        self::assertCount(2, WhmcsCalls::$activity);
        self::assertSame('Agency Discount: profile save for client 600 by admin:2 ignored: the admin role is not on the addon\'s access list', WhmcsCalls::$activity[0]['message']);

        unset(\WHMCS\Session::$values['adminid']);
        Hooks::profileSave(['userid' => 600], $approve);
        self::assertNull($this->repo->agency(600), 'no admin in the session');
        self::assertStringContainsString('by admin ignored', WhmcsCalls::$activity[2]['message']);

        \WHMCS\Session::$values['adminid'] = 1;
        Hooks::profileSave(['userid' => 600], $approve);
        self::assertTrue($this->repo->agency(600)['approved'] ?? false);
    }

    public function testProfileSaveIgnoresRequestsWithoutMarker(): void
    {
        $this->useModule(Fixtures::settings());
        Hooks::profileSave(['userid' => 131], ['firstname' => 'x']);
        self::assertTrue($this->repo->agency(131)['approved'] ?? false);
    }

    public function testDailyPruneRemovesOnlyLogEntriesOlderThanTheRetention(): void
    {
        $event = ['clientId' => 131, 'invoiceId' => null, 'event' => 'tier_changed', 'detail' => [], 'actor' => 'test'];
        $this->repo->log($event + ['createdAt' => '2024-10-18 01:59:59']);
        $this->repo->log($event + ['createdAt' => '2024-10-18 02:00:00']);
        $this->repo->log($event + ['createdAt' => '2026-10-01 00:00:00']);
        $this->repo->saveInvoiceRecord(['invoiceId' => 1, 'line' => 0, 'clientId' => 131, 'itemId' => 1, 'taxed' => false, 'tierIndex' => 1, 'percent' => '5.00', 'base' => '1.00', 'amount' => '0.05', 'dryRun' => false, 'createdAt' => '2020-01-01 00:00:00']);
        $this->useModule(Fixtures::settings());
        Hooks::pruneLog();
        self::assertSame(['log_pruned', 'tier_changed', 'tier_changed'], array_column($this->repo->logEntries(null, 10), 'event'));
        self::assertSame(['2026-10-01 00:00:00', '2024-10-18 02:00:00'], array_column($this->repo->logEntries(null, 10, 0, 'tier_changed'), 'createdAt'));
        $pruned = $this->repo->logEntries(null, 1)[0];
        self::assertSame(['count' => 1, 'before' => '2024-10-18 02:00:00', 'months' => 24], $pruned['detail']);
        self::assertSame('cron:daily', $pruned['actor']);
        self::assertNotNull($this->repo->invoiceRecord(1), 'invoice records are never pruned');
        self::assertSame([], WhmcsCalls::$activity);
        // nothing to prune: no entry either
        Hooks::reset();
        $this->useModule(Fixtures::settings());
        Hooks::pruneLog();
        self::assertSame(1, substr_count(implode(',', array_column($this->repo->logEntries(null, 10), 'event')), 'log_pruned'));
    }

    public function testPruneRunsInBatchesAndClampsTheCutOffToTheMonthEnd(): void
    {
        $event = ['clientId' => 131, 'invoiceId' => null, 'event' => 'tier_changed', 'detail' => [], 'actor' => 'test'];
        for ($i = 0; $i < 2500; $i++) {
            $this->repo->log($event + ['createdAt' => '2020-01-01 00:00:00']);
        }
        $this->repo->log($event + ['createdAt' => '2026-02-28 02:00:00']);
        $this->repo->log($event + ['createdAt' => '2026-02-28 01:59:59']);
        // 31 March 2026 02:00 less one month is 28 February 2026 02:00, not 3 March; the time of day is kept
        $module = new Module($this->repo, Config::fromSettings(['log_retention_months' => '1'] + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-03-31 02:00:00')), new RecordingActivityLog());
        // an old log_pruned entry goes too, but is neither counted nor a reason to write a new one
        $this->repo->log(['clientId' => null, 'invoiceId' => null, 'event' => 'log_pruned', 'detail' => ['count' => 5, 'before' => '2019-01-01 00:00:00', 'months' => 1], 'actor' => 'cron:daily', 'createdAt' => '2020-01-02 00:00:00']);
        self::assertSame(2501, $module->pruneLog());
        self::assertSame([1000, 1000, 501], $this->repo->pruneCalls, 'three batches: two full, one short');
        self::assertSame(['log_pruned', 'tier_changed'], array_column($this->repo->logEntries(null, 10), 'event'));
        self::assertSame('2026-02-28 02:00:00', $this->repo->logEntries(null, 1)[0]['detail']['before']);
        self::assertSame('2501 change log entries older than 2026-02-28 removed (retention 1 month).', LogEntry::sentence($this->repo->logEntries(null, 1)[0], []));
        self::assertSame('1 change log entry older than 2026-02-28 removed (retention 24 months).', LogEntry::sentence(['event' => 'log_pruned', 'detail' => ['count' => 1, 'before' => '2026-02-28 02:00:00', 'months' => 24], 'clientId' => null, 'invoiceId' => null, 'actor' => 'cron:daily', 'createdAt' => ''] + ['id' => 1], []));

        // a month later only the log_pruned entry itself is old enough: it is removed and nothing is written, so a prune does not keep itself going
        $later = new Module($this->repo, Config::fromSettings(['log_retention_months' => '1'] + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-04-30 02:00:00')), new RecordingActivityLog());
        foreach ($this->repo->logs as $i => $row) {
            $this->repo->logs[$i]['createdAt'] = $row['event'] === 'tier_changed' ? '2026-04-20 00:00:00' : '2026-03-29 00:00:00';
        }
        self::assertSame(0, $later->pruneLog());
        self::assertSame(['tier_changed'], array_column($this->repo->logEntries(null, 10), 'event'), 'no entry written, the old bookkeeping row gone');

        // an exact multiple of the batch size: one extra empty batch, then stop
        $this->repo->pruneCalls = [];
        for ($i = 0; $i < 2000; $i++) {
            $this->repo->log($event + ['createdAt' => '2020-01-01 00:00:00']);
        }
        self::assertSame(2000, $later->pruneLog());
        self::assertSame([1000, 1000, 0], $this->repo->pruneCalls);
    }

    public function testRetentionOfZeroKeepsTheWholeLog(): void
    {
        $this->repo->log(['clientId' => 131, 'invoiceId' => null, 'event' => 'tier_changed', 'detail' => [], 'actor' => 'test', 'createdAt' => '2000-01-01 00:00:00']);
        $this->useModule(['log_retention_months' => '0'] + Fixtures::settings());
        Hooks::pruneLog();
        self::assertCount(1, $this->repo->logEntries(null, 10));
    }
}
