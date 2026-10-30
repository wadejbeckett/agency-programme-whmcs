<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\OverviewTab;
use AgencyDiscount\Admin\Page;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** The Overview tab, rendered through Page: tiles, the agencies table with filters and flags, and the side column. */
final class OverviewTest extends TestCase
{
    private const LINK = 'addonmodules.php?module=agencydiscount';
    private const TOKEN = '<input type="hidden" name="token" value="t">';

    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        $this->repo->settings = Fixtures::settings();
        foreach ([131, 404, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    /** @param array<string,string> $override */
    private function page(array $override = []): Page
    {
        return Page::of(new Module($this->repo, Config::fromSettings($override + $this->repo->settings), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog()));
    }

    /**
     * @param array<string,mixed> $get
     * @param array<string,string> $override
     */
    private function render(array $get = [], array $override = []): string
    {
        return $this->page($override)->render(self::LINK, $get, [], 'admin:1', self::TOKEN);
    }

    /** The table row that links to the client, or '' */
    private static function row(string $html, int $clientId): string
    {
        return preg_match('#<tr[^>]*>(?:(?!</tr>).)*userid=' . $clientId . '(?:(?!</tr>).)*</tr>#s', $html, $m) === 1 ? $m[0] : '';
    }

    public function testTilesSummariseTheProgramme(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = $this->render();
        self::assertStringContainsString('<div class="agencydiscount-tile-value">3</div><div class="agencydiscount-tile-label">Approved agencies</div>', $html);
        self::assertStringContainsString('2 at a tier · 1 waiting for a start date', $html);
        self::assertStringContainsString('Qualifying spend per month', $html);
        self::assertStringContainsString('<div class="agencydiscount-tile-value">ZAR 7,012.92</div>', $html);
        self::assertStringContainsString('<div class="agencydiscount-tile-value">EUR 300.00</div>', $html);
        self::assertStringContainsString('Discount in the next 35 days', $html);
        // 404: 15% of ZAR 6,000.00 on 2026-10-20; 131 waits for its start date, so nothing in the window
        self::assertStringContainsString('<div class="agencydiscount-tile-value">ZAR 900.00</div>', $html);
        self::assertStringContainsString('<div class="agencydiscount-tile-value">EUR 0.00</div>', $html);
        self::assertStringContainsString('<span class="label label-success">Live</span>', $html);
        self::assertStringContainsString('<span class="label label-warning">Dry-run</span>', $this->render([], ['dry_run' => 'on']));
    }

    public function testTableShowsBadgesProgressAndFlags(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = $this->render();

        $one = self::row($html, 131);
        self::assertStringStartsWith('<tr class="warning">', $one);
        self::assertStringContainsString('<span class="label label-success">Tier 1 (5%)</span>', $one);
        self::assertStringContainsString('ZAR 1,012.92', $one);
        self::assertStringContainsString('<div class="progress-bar" role="progressbar" aria-valuenow="1" aria-valuemin="0" aria-valuemax="100" style="width: 1%">', $one);
        self::assertStringContainsString('ZAR 987.09 to tier 2 (10%)', $one);
        self::assertStringContainsString('<span class="agencydiscount-nowrap">2026-10-25</span><br><small class="agencydiscount-nowrap">no discount on ZAR 850.00</small>', $one);
        self::assertStringContainsString('<span class="text-warning">starts 2027-01-01</span>', $one);
        self::assertStringContainsString('<a href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=131">Detail</a>', $one);

        $two = self::row($html, 404);
        self::assertStringStartsWith('<tr>', $two);
        self::assertStringContainsString('<span class="label label-success">Tier 3 (15%)</span>', $two);
        self::assertStringContainsString('style="width: 100%"', $two);
        self::assertStringContainsString('top tier', $two);
        self::assertStringContainsString('<span class="agencydiscount-nowrap">2026-10-20</span><br><small class="agencydiscount-nowrap">ZAR 900.00 off ZAR 6,000.00</small>', $two);
        self::assertStringContainsString('<br><small class="agencydiscount-nowrap">active &middot; <a href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=404">Detail</a></small></td>', $two);

        $euro = self::row($html, 500);
        self::assertStringStartsWith('<tr class="danger">', $euro);
        self::assertStringContainsString('<span class="label label-danger">no tiers for EUR</span>', $euro);
        self::assertStringNotContainsString('progress-bar', $euro);

        $this->repo->agencies[131]['effectiveFrom'] = '2026-01-01';
        self::assertStringContainsString('<small class="agencydiscount-nowrap">active since 2026-01-01 &middot; ', self::row($this->render(), 131));
    }

    public function testTableHasFiveColumnsAndOneRecalculationLine(): void
    {
        $html = $this->render();
        self::assertStringContainsString('<thead><tr><th>Client</th><th>Qualifying monthly spend</th><th>Tier</th><th>To next tier</th><th>Next invoice</th></tr></thead>', $html);
        self::assertStringContainsString('<p class="text-muted"><small>Figures not recalculated yet.</small></p>', $html);

        foreach ([131, 404, 500] as $id) {
            $this->repo->agencies[$id]['calculatedAt'] = '2026-10-18 01:00:00';
        }
        self::assertStringContainsString('Figures recalculated 2026-10-18 01:00:00.', $this->render());
        $this->repo->agencies[500]['calculatedAt'] = '2026-10-17 01:00:00';
        $html = $this->render();
        self::assertStringContainsString('Figures recalculated between 2026-10-17 01:00:00 and 2026-10-18 01:00:00.', $html);
        self::assertStringContainsString('title="recalculated 2026-10-17 01:00:00">EUR 300.00</span>', self::row($html, 500));
    }

    public function testBelowTheFirstTierShowsNoTierBadge(): void
    {
        $this->repo->services[2001]['amount'] = '400.00';
        $two = self::row($this->render(), 404);
        self::assertStringContainsString('<span class="label label-default">No tier</span>', $two);
        self::assertStringContainsString('style="width: 40%"', $two);
        self::assertStringContainsString('ZAR 600.01 to tier 1 (5%)', $two);
        self::assertStringContainsString('<span class="agencydiscount-nowrap">2026-10-20</span><br><small class="agencydiscount-nowrap">no discount on ZAR 400.00</small>', $two);
    }

    public function testFiltersByStateAndByName(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $all = $this->render();
        foreach ([131, 404, 500] as $id) {
            self::assertNotSame('', self::row($all, $id));
        }
        self::assertStringContainsString('<a class="btn btn-default btn-sm active" href="addonmodules.php?module=agencydiscount&amp;filter=all">All (3)</a>', $all);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;filter=tier">At a tier (2)</a>', $all);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;filter=waiting">Not yet started (1)</a>', $all);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;filter=no_tiers">No tiers for currency (1)</a>', $all);

        $tier = $this->render(['filter' => 'tier']);
        self::assertSame('', self::row($tier, 500));
        self::assertNotSame('', self::row($tier, 131));
        self::assertNotSame('', self::row($tier, 404));
        self::assertStringContainsString('<a class="btn btn-default btn-sm active" href="addonmodules.php?module=agencydiscount&amp;filter=tier">', $tier);

        $waiting = $this->render(['filter' => 'waiting']);
        self::assertNotSame('', self::row($waiting, 131));
        self::assertSame('', self::row($waiting, 404));

        $none = $this->render(['filter' => 'no_tiers']);
        self::assertNotSame('', self::row($none, 500));
        self::assertSame('', self::row($none, 131));

        $named = $this->render(['filter' => 'all', 'q' => 'euro']);
        self::assertNotSame('', self::row($named, 500));
        self::assertSame('', self::row($named, 131));
        self::assertStringContainsString('<input type="hidden" name="module" value="agencydiscount">', $named);
        self::assertStringContainsString('<input type="hidden" name="filter" value="all">', $named);
        self::assertStringContainsString('name="q" value="euro"', $named);
        self::assertStringContainsString('filter=tier&amp;q=euro', $named);

        $empty = $this->render(['filter' => 'waiting', 'q' => 'nobody']);
        self::assertStringContainsString('No agencies match this filter.', $empty);
        self::assertSame('', self::row($empty, 131));

        self::assertNotSame('', self::row($this->render(['filter' => 'bogus']), 500), 'an unknown filter shows everything');
        // the tiles always count every agency
        self::assertStringContainsString('<div class="agencydiscount-tile-value">3</div>', $named);
    }

    public function testNeedsAttentionLists(): void
    {
        $this->repo->services[2001]['amount'] = '2950.00';
        $this->repo->log(['clientId' => null, 'invoiceId' => null, 'event' => 'mode_changed', 'detail' => ['from' => 'live', 'to' => 'dry-run'], 'actor' => 'admin:1', 'createdAt' => '2026-09-30 10:00:00']);
        $html = $this->render([], ['dry_run' => 'on']);
        self::assertStringContainsString('Needs attention', $html);
        self::assertStringContainsString('Dry-run mode has been on for 12 days.', $html);
        self::assertStringContainsString('EUR has no tiers: 1 approved agency gets no discount.', $html);
        self::assertStringContainsString('Agency Two is ZAR 50.01 from tier 3 (15%).', $html);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;action=settings"', $html);
        self::assertStringContainsString('href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=404"', $html);
        self::assertStringNotContainsString('Nothing needs attention', $html);
    }

    public function testTheRecalculatedLineDescribesTheRowsShown(): void
    {
        $this->repo->agencies[131]['calculatedAt'] = '2026-10-12 03:00:00';
        $this->repo->agencies[404]['calculatedAt'] = '2026-10-11 03:00:00';
        $this->repo->agencies[500]['calculatedAt'] = '2026-10-10 03:00:00';
        self::assertStringContainsString('Figures recalculated between 2026-10-10 03:00:00 and 2026-10-12 03:00:00.', $this->render());
        self::assertStringContainsString('Figures recalculated 2026-10-10 03:00:00.', $this->render(['filter' => 'no_tiers']), 'only the Euro agency is shown');
        self::assertStringContainsString('Figures recalculated 2026-10-12 03:00:00.', $this->render(['q' => 'Agency One']));
    }

    public function testAFailedApplyIsListedWithARetryThatAppliesTheDiscount(): void
    {
        $this->repo->log(['clientId' => 131, 'invoiceId' => 9001, 'event' => 'apply_failed', 'detail' => ['message' => 'insert failed', 'amount' => '42.50'], 'actor' => 'system', 'createdAt' => '2026-10-10 03:00:00']);
        $this->repo->log(['clientId' => 131, 'invoiceId' => 8000, 'event' => 'apply_failed', 'detail' => ['message' => 'old'], 'actor' => 'system', 'createdAt' => '2026-08-01 03:00:00']);
        $html = $this->render();
        self::assertStringContainsString('<div class="list-group-item"><form method="post" class="form-inline">' . self::TOKEN . '<input type="hidden" name="action" value="retry"><input type="hidden" name="invoice" value="9001"><a href="invoices.php?action=edit&amp;id=9001">Invoice #9001 got no discount</a> (failed on 2026-10-10: insert failed). <button type="submit" class="btn btn-default btn-xs">Retry</button></form></div>', $html);
        self::assertStringNotContainsString('value="8000"', $html, 'older than 35 days');

        // a second failure of the same invoice, a failure with no invoice, a deleted invoice and a paid one add no items
        $this->repo->log(['clientId' => 131, 'invoiceId' => 9001, 'event' => 'apply_failed', 'detail' => ['message' => 'again'], 'actor' => 'system', 'createdAt' => '2026-10-11 03:00:00']);
        $this->repo->log(['clientId' => 131, 'invoiceId' => null, 'event' => 'apply_failed', 'detail' => ['message' => 'no invoice'], 'actor' => 'system', 'createdAt' => '2026-10-11 03:00:01']);
        $this->repo->log(['clientId' => 131, 'invoiceId' => 7777, 'event' => 'apply_failed', 'detail' => ['message' => 'deleted since'], 'actor' => 'system', 'createdAt' => '2026-10-11 03:00:02']);
        $this->repo->invoices[9002]['status'] = 'Paid';
        $this->repo->log(['clientId' => 131, 'invoiceId' => 9002, 'event' => 'apply_failed', 'detail' => ['message' => 'paid since'], 'actor' => 'system', 'createdAt' => '2026-10-11 03:00:03']);
        $html = $this->render();
        self::assertSame(1, substr_count($html, 'name="action" value="retry"'), 'one item, for the one open failure');
        self::assertStringContainsString('(failed on 2026-10-11: again)', $html, 'the newest failure of the invoice is shown');
        self::assertStringNotContainsString('value="7777"', $html);
        self::assertStringNotContainsString('value="9002"', $html);

        // in dry-run the button gives way to a note and a retry is refused
        $html = $this->render([], ['dry_run' => 'on']);
        self::assertStringContainsString('(failed on 2026-10-11: again). <span class="text-muted">Retry when live mode is on</span></form>', $html);
        $html = $this->page(['dry_run' => 'on'])->render(self::LINK, [], ['action' => 'retry', 'invoice' => '9001'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-warning">Invoice #9001 was not retried: dry-run is on. Switch to live mode first.</div>', $html);
        self::assertNull($this->repo->invoiceRecord(9001));

        // a retry on a paid or deleted invoice is refused before apply() runs
        $html = $this->page()->render(self::LINK, [], ['action' => 'retry', 'invoice' => '9002'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-warning">Invoice #9002 is Paid; a discount can only be added to an unpaid or draft invoice.</div>', $html);
        $html = $this->page()->render(self::LINK, [], ['action' => 'retry', 'invoice' => '7777'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-warning">Invoice #7777 no longer exists.</div>', $html);
        self::assertSame([], $this->repo->refreshedInvoices);

        // Retry applies the discount (live mode), has WHMCS refresh the totals, and the item is gone
        $html = $this->page()->render(self::LINK, [], ['action' => 'retry', 'invoice' => '9001'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-success">Discount applied to invoice #9001.</div>', $html);
        self::assertNotNull($this->repo->invoiceRecord(9001));
        self::assertSame([9001], $this->repo->refreshedInvoices);
        self::assertStringNotContainsString('Invoice #9001 got no discount', $html);
        self::assertSame('applied', $this->repo->logEntries(131, 1)[0]['event']);
        self::assertSame('retry', $this->repo->logEntries(131, 1)[0]['detail']['source']);

        // a retry on an invoice that is already done, or with no id, says so in words
        $html = $this->page()->render(self::LINK, [], ['action' => 'retry', 'invoice' => '9001'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-info">Invoice #9001: nothing applied. The invoice already has its discount.</div>', $html);
        $html = $this->page()->render(self::LINK, [], ['action' => 'retry', 'invoice' => 'x'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-danger">No invoice given to retry.</div>', $html);
    }

    public function testARetryWhoseTotalsRefreshFailsSaysSoAndALongMessageIsCut(): void
    {
        $this->repo->log(['clientId' => 131, 'invoiceId' => 9001, 'event' => 'apply_failed', 'detail' => ['message' => str_repeat('é', 150)], 'actor' => 'system', 'createdAt' => '2026-10-10 03:00:00']);
        self::assertStringContainsString('(failed on 2026-10-10: ' . str_repeat('é', 119) . '…).', $this->render());
        $this->repo->refreshSucceeds = false;
        $html = $this->page()->render(self::LINK, [], ['action' => 'retry', 'invoice' => '9001'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-warning">Discount line added to invoice #9001, but WHMCS did not recalculate its total: open the invoice and save it.</div>', $html);
        self::assertNotNull($this->repo->invoiceRecord(9001));
    }

    public function testARetryThatFailsAgainReportsTheErrorAndKeepsTheItem(): void
    {
        $failing = new class (Fixtures::data()) extends ArrayRepository {
            public function saveInvoiceRecord(array $row): void
            {
                throw new \RuntimeException('still failing');
            }
        };
        $failing->agencies = $this->repo->agencies;
        $failing->log(['clientId' => 131, 'invoiceId' => 9001, 'event' => 'apply_failed', 'detail' => ['message' => 'first'], 'actor' => 'system', 'createdAt' => '2026-10-10 03:00:00']);
        $page = Page::of(new Module($failing, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog()));
        $html = $page->render(self::LINK, [], ['action' => 'retry', 'invoice' => '9001'], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<div class="alert alert-danger">Invoice #9001: the retry failed (still failing). The attempt is in the change log.</div>', $html);
        self::assertStringContainsString('(failed on 2026-10-12: still failing).', $html, 'the new failure is the one listed');
        self::assertSame(1, substr_count($html, 'name="action" value="retry"'));
    }

    public function testNeedsAttentionNamesEngineRowsWithNoStoredValue(): void
    {
        $this->repo->settings = ['statuses' => 'Active', 'description' => 'x {percent}', 'product_groups' => '23', 'activity_log' => 'on'];
        $html = $this->page()->render(self::LINK, [], [], 'admin:1', self::TOKEN);
        self::assertStringContainsString('<a class="list-group-item" href="addonmodules.php?module=agencydiscount&amp;action=settings">No stored value for tiers, dry_run, products, addons: the defaults apply (no tiers, dry-run on, no products count, no add-ons count) until the Settings tab is saved.</a>', $html);
        $this->repo->settings = Fixtures::settings();
        self::assertStringNotContainsString('No stored value', $this->render());
    }

    public function testNeedsAttentionListsWhatWhmcsDoesNotHave(): void
    {
        $html = $this->render([], ['product_groups' => '23,999', 'products' => '113,777,778', 'addons' => '12,888', 'tiers' => '{"ZAR":[{"above":1000,"percent":5}],"USD":[{"above":0,"percent":5}]}']);
        $link = 'href="addonmodules.php?module=agencydiscount&amp;action=settings"';
        self::assertStringContainsString('<a class="list-group-item" ' . $link . '>Product group ids not found in WHMCS: 999.</a>', $html);
        self::assertStringContainsString('<a class="list-group-item" ' . $link . '>Product ids not found in WHMCS: 777, 778.</a>', $html);
        self::assertStringContainsString('<a class="list-group-item" ' . $link . '>Add-on ids not found in WHMCS: 888.</a>', $html);
        self::assertStringContainsString('<a class="list-group-item" ' . $link . '>Tiers are configured for USD, which is not a WHMCS currency.</a>', $html);
        self::assertStringContainsString('EUR has no tiers: 1 approved agency gets no discount.', $html);
        $clean = $this->render([], ['products' => '113']);
        self::assertStringNotContainsString('not found in WHMCS', $clean);
        self::assertStringNotContainsString('not a WHMCS currency', $clean);
    }

    public function testDryRunAgeFallsBackToTheFirstApproval(): void
    {
        $html = $this->render([], ['dry_run' => 'on']);
        self::assertStringContainsString('Dry-run mode has been on for at least 11 days.', $html);
        $this->repo->agencies = [];
        self::assertStringContainsString('Dry-run mode is on.', $this->render([], ['dry_run' => 'on']));
    }

    public function testNothingNeedsAttention(): void
    {
        unset($this->repo->agencies[500]);
        $html = $this->render();
        self::assertStringContainsString('Nothing needs attention.', $html);
        self::assertStringNotContainsString('Dry-run mode', $html);
    }

    public function testRecentActivityShowsTheSixNewestAsSentences(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->repo->log(['clientId' => 131, 'invoiceId' => 9000 + $i, 'event' => 'dry_run', 'detail' => ['amount' => '4' . $i . '.00', 'percent' => '5.00'], 'actor' => 'system', 'createdAt' => '2026-10-1' . ($i % 3) . ' 03:00:00']);
        }
        $this->repo->log(['clientId' => 600, 'invoiceId' => null, 'event' => 'revoked', 'detail' => ['note' => ''], 'actor' => 'admin:1', 'createdAt' => '2026-10-12 08:00:00']);
        $html = $this->render();
        self::assertStringContainsString('Recent activity', $html);
        self::assertStringContainsString('Retail Client&#039;s agency approval revoked by admin:1.', $html);
        self::assertStringContainsString('Dry run: ZAR 48.00 (5%) would have come off invoice #9008 for Agency One.', $html);
        self::assertStringContainsString('invoice #9004', $html);
        self::assertStringNotContainsString('invoice #9003', $html);
        self::assertSame(OverviewTab::RECENT_ENTRIES, 6);
        self::assertStringContainsString('<a href="addonmodules.php?module=agencydiscount&amp;action=log">Full change log</a>', $html);
    }

    public function testNoActivityYet(): void
    {
        self::assertStringContainsString('No activity yet.', $this->render());
    }

    public function testNoAgenciesYet(): void
    {
        $this->repo->agencies = [];
        $html = $this->render();
        self::assertStringContainsString('No approved agencies yet. Approve a client on its profile tab.', $html);
        self::assertStringContainsString('<div class="agencydiscount-tile-value">0</div>', $html);
        self::assertStringContainsString('Recalculate now', $html);
    }

    public function testChangeLogTabRendersSentences(): void
    {
        $this->repo->log(['clientId' => 131, 'invoiceId' => 9001, 'event' => 'applied', 'detail' => ['amount' => '42.50', 'percent' => '5.00', 'itemId' => 9], 'actor' => 'system', 'createdAt' => '2026-10-12 03:00:00']);
        $html = $this->render(['action' => 'log']);
        self::assertStringContainsString('Discount of ZAR 42.50 (5%) added to invoice #9001 for Agency One.', $html);
        self::assertStringContainsString('<code>', $html);
    }
}
