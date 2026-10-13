<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\Page;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        foreach ([131, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'effectiveFrom' => null, 'currency' => '', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-01 00:00:00']);
        }
    }

    /** @param array<string,string> $override */
    private function page(array $override = []): Page
    {
        return new Page(new Module($this->repo, Config::fromSettings($override + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog()));
    }

    public function testOverviewListsAgenciesWithLiveFigures(): void
    {
        $html = $this->page(['dry_run' => 'on'])->render('addonmodules.php?module=agencydiscount', [], [], 'admin:1', '<input type="hidden" name="token" value="t">');
        self::assertStringContainsString('Dry-run mode is on', $html);
        self::assertStringContainsString('Agency One', $html);
        self::assertStringContainsString('ZAR 1,012.92', $html);
        self::assertStringContainsString('1 (5%)', $html);
        self::assertStringContainsString('2026-10-25: ZAR 42.50 off ZAR 850.00', $html);
        self::assertStringContainsString('ZAR 987.09', $html);
        self::assertStringContainsString('clientssummary.php?userid=131', $html);
        self::assertStringContainsString('name="token" value="t"', $html);
        self::assertStringNotContainsString('Retail Client', $html);
        self::assertNull($this->repo->agency(131)['calculatedAt']);
        self::assertSame([], $this->repo->logEntries(131, 10));
        $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertNull($this->repo->agency(131)['calculatedAt']);
        self::assertSame([], $this->repo->logEntries(131, 10));
    }

    public function testUpcomingInvoicesBeforeStartDateShowNoDiscount(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2026-11-01';
        $html = $this->page()->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('2026-10-25: no discount on ZAR 850.00', $html);
        $detail = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('<td>2026-10-25</td><td>5</td><td>ZAR 850.00</td><td>ZAR 0.00</td>', $detail);
        self::assertStringContainsString('<td>2026-11-03</td><td>2</td><td>ZAR 1,955.00</td><td>ZAR 97.75</td>', $detail);
    }

    public function testOverviewFlagsCurrencyWithoutTiers(): void
    {
        $html = $this->page()->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('no tiers for EUR', $html);
        self::assertStringNotContainsString('Dry-run mode is on', $html);
    }

    public function testRecalculatePostStoresAndReports(): void
    {
        $html = $this->page()->render('m', [], ['action' => 'recalculate'], 'admin:1', '');
        self::assertStringContainsString('Recalculated 2 agencies.', $html);
        self::assertSame(1, $this->repo->agency(131)['tierIndex'] ?? null);
        self::assertSame('admin:1', $this->repo->logEntries(131, 1)[0]['actor']);
    }

    public function testAgencyDetailAndUnknown(): void
    {
        $html = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('a.example', $html);
        self::assertStringContainsString('Advanced Email Security', $html);
        self::assertStringContainsString('2026-11-03', $html);
        self::assertStringContainsString('ZAR 97.75', $html);
        self::assertStringNotContainsString('e.example', $html);
        self::assertStringContainsString('<a href="m&amp;action=log&amp;client=131">Change log for this client</a>', $html);
        $bad = $this->page()->render('m', ['action' => 'agency', 'id' => '600'], [], 'admin:1', '');
        self::assertStringContainsString('Client 600 is not an approved agency', $bad);
    }

    public function testLogViewEscapesAndPaginates(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->repo->log(['clientId' => 131, 'invoiceId' => null, 'event' => 'note_changed', 'detail' => ['to' => "<script>$i"], 'actor' => 'admin:1', 'createdAt' => '2026-10-12 09:00:00']);
        }
        $html = $this->page()->render('m', ['action' => 'log'], [], 'admin:1', '');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;60', $html);
        self::assertStringNotContainsString('&lt;script&gt;10"', $html);
        self::assertStringContainsString('page=2', $html);
        $page2 = $this->page()->render('m', ['action' => 'log', 'page' => '2'], [], 'admin:1', '');
        self::assertStringContainsString('&lt;script&gt;10', $page2);
    }

    public function testConfigurationCheckNamesIdsAndFlagsMissing(): void
    {
        $html = $this->page(['products' => '113,777'])->render('m', ['action' => 'check'], [], 'admin:1', '');
        self::assertStringContainsString('Premium Shared', $html);
        self::assertStringContainsString('sPremium-1', $html);
        self::assertStringContainsString('777', $html);
        self::assertStringContainsString('not found', $html);
        self::assertStringContainsString('Edge Security (WAF, CDN &amp; DDoS)', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringContainsString('EUR', $html);
    }

    public function testCatalogueNamesAreEscapedExactlyOnce(): void
    {
        $detail = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('Edge Security (WAF, CDN &amp; DDoS)', $detail);
        self::assertStringNotContainsString('&amp;amp;', $detail);
    }

    public function testConfigProblemsPage(): void
    {
        $html = Page::configProblems('m', ['tiers: invalid JSON']);
        self::assertStringContainsString('tiers: invalid JSON', $html);
        self::assertStringContainsString('alert-danger', $html);
    }

    public function testOverviewShowsStartDate(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = $this->page()->render('m', [], [], 'admin:1', '');
        self::assertStringContainsString('starts 2027-01-01', $html);
        self::assertStringContainsString('<span class="text-warning">starts 2027-01-01</span>', $html);
        self::assertSame(1, preg_match('#<tr>(?:(?!</tr>).)*userid=500(?:(?!</tr>).)*</tr>#s', $html, $row));
        self::assertStringContainsString('active', $row[0]);
        self::assertStringNotContainsString('starts', $row[0]);
    }

    public function testAgencyDetailShowsStartDate(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $html = $this->page()->render('m', ['action' => 'agency', 'id' => '131'], [], 'admin:1', '');
        self::assertStringContainsString('starts 2027-01-01', $html);
    }

    public function testNoClientNameIsEverUnescaped(): void
    {
        $this->repo->clients[131]['name'] = '<b>Evil</b> & Co';
        foreach ([[], ['action' => 'agency', 'id' => '131'], ['action' => 'log']] as $get) {
            $html = $this->page()->render('m', $get, [], 'admin:1', '');
            self::assertStringNotContainsString('<b>Evil', $html);
        }
        self::assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; &amp; Co', $this->page()->render('m', [], [], 'admin:1', ''));
    }
}
