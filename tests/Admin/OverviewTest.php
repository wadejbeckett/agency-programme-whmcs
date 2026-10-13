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
        return new Page(new Module($this->repo, Config::fromSettings($override + $this->repo->settings), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog()));
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
        self::assertStringContainsString('2026-10-25: no discount on ZAR 850.00', $one);
        self::assertStringContainsString('<span class="text-warning">starts 2027-01-01</span>', $one);
        self::assertStringContainsString('<a href="addonmodules.php?module=agencydiscount&amp;action=agency&amp;id=131">Detail</a>', $one);

        $two = self::row($html, 404);
        self::assertStringStartsWith('<tr>', $two);
        self::assertStringContainsString('<span class="label label-success">Tier 3 (15%)</span>', $two);
        self::assertStringContainsString('style="width: 100%"', $two);
        self::assertStringContainsString('top tier', $two);
        self::assertStringContainsString('2026-10-20: ZAR 900.00 off ZAR 6,000.00', $two);
        self::assertStringContainsString('<td>active</td>', $two);

        $euro = self::row($html, 500);
        self::assertStringStartsWith('<tr class="danger">', $euro);
        self::assertStringContainsString('<span class="label label-danger">no tiers for EUR</span>', $euro);
        self::assertStringNotContainsString('progress-bar', $euro);

        $this->repo->agencies[131]['effectiveFrom'] = '2026-01-01';
        self::assertStringContainsString('<td>active since 2026-01-01</td>', self::row($this->render(), 131));
    }

    public function testBelowTheFirstTierShowsNoTierBadge(): void
    {
        $this->repo->services[2001]['amount'] = '400.00';
        $two = self::row($this->render(), 404);
        self::assertStringContainsString('<span class="label label-default">No tier</span>', $two);
        self::assertStringContainsString('style="width: 40%"', $two);
        self::assertStringContainsString('ZAR 600.01 to tier 1 (5%)', $two);
        self::assertStringContainsString('2026-10-20: no discount on ZAR 400.00', $two);
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
