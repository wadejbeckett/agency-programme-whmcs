<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\AgencyTab;
use AgencyDiscount\Admin\Page;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/** The agency detail view, rendered through Page (nav, banner) and directly. */
final class AgencyTabTest extends TestCase
{
    private const LINK = 'addonmodules.php?module=agencydiscount';

    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        foreach ([131, 404, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        }
        $this->repo->agencies[131]['note'] = 'Partner <since> 2024';
        $this->repo->agencies[131]['calculatedAt'] = '2026-10-12 03:00:00';
        // records: applied this year, a dry run, a cancelled invoice, a line removed by hand, a deleted invoice, last year's
        $record = ['line' => 0, 'taxed' => false, 'clientId' => 131, 'itemId' => 1, 'tierIndex' => 1, 'percent' => '5.00', 'base' => '850.00', 'amount' => '42.50', 'dryRun' => false, 'createdAt' => '2026-03-25 10:00:00'];
        $this->repo->saveInvoiceRecord(['invoiceId' => 1] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 2, 'amount' => '10.00', 'dryRun' => true, 'itemId' => null, 'createdAt' => '2026-04-01 10:00:00'] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 3, 'amount' => '20.00', 'createdAt' => '2026-05-01 10:00:00'] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 4, 'amount' => '30.00', 'createdAt' => '2026-06-01 10:00:00'] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 5, 'amount' => '40.00', 'createdAt' => '2026-07-01 10:00:00'] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 6, 'amount' => '99.00', 'taxed' => true, 'tierIndex' => 2, 'percent' => '10.00', 'createdAt' => '2025-12-25 10:00:00'] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 6, 'line' => 1, 'amount' => '1.00', 'createdAt' => '2025-12-25 10:00:00'] + $record);
        Fixtures::withDiscountLines($this->repo);
        $this->repo->invoices[3]['status'] = 'Cancelled';
        $this->repo->invoiceItems[4] = [];
        unset($this->repo->invoices[5], $this->repo->invoiceItems[5]);
    }

    /** @param array<string,string> $override */
    private function module(array $override = []): Module
    {
        return new Module($this->repo, Config::fromSettings($override + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
    }

    /** @param array<string,string> $override */
    private function render(int $clientId, array $override = []): string
    {
        return (new AgencyTab($this->module($override)))->render(self::LINK, $clientId);
    }

    public function testAgencyAtATier(): void
    {
        $html = $this->render(131);
        // header panel
        self::assertStringContainsString('<h3 class="panel-title"><a href="clientssummary.php?userid=131">Agency One</a> <small class="text-muted">ZAR</small></h3>', $html);
        self::assertStringContainsString('<span class="label label-success">Tier 1 (5%)</span> <span class="text-muted">active</span>', $html);
        self::assertStringContainsString('ZAR 1,012.92 <span class="text-muted">(7 counted items)</span>', $html);
        self::assertStringContainsString('class="progress-bar"', $html);
        self::assertStringContainsString('ZAR 987.09 to tier 2 (10%)', $html);
        self::assertStringContainsString('<th class="agencydiscount-detail-label">Approved</th><td>Approved by admin:1 on 2026-10-01</td>', $html);
        self::assertStringContainsString('<th class="agencydiscount-detail-label">Note</th><td>Partner &lt;since&gt; 2024</td>', $html);
        self::assertStringContainsString('<th class="agencydiscount-detail-label">Figures recalculated</th><td>2026-10-12 03:00:00</td>', $html);
        // counted items and upcoming invoices
        self::assertStringContainsString('<h4>Counted services</h4>', $html);
        self::assertStringContainsString('<td>a.example</td><td>sPremium-1</td><td>Monthly</td><td>ZAR 170.00</td><td>ZAR 170.00</td><td>2026-10-25</td><td>no</td>', $html);
        self::assertStringContainsString('<td>f.example</td>', $html);
        self::assertStringContainsString('<td>yes</td>', $html, 'the promoted service is flagged');
        self::assertStringContainsString('Edge Security (WAF, CDN &amp; DDoS)', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringNotContainsString('e.example', $html, 'a service outside the counted groups is not listed');
        self::assertStringContainsString('<h4>Upcoming invoices (next 35 days)</h4>', $html);
        self::assertStringContainsString('<td>2026-10-25</td><td>5</td><td>ZAR 850.00</td><td>ZAR 42.50</td>', $html);
        // records, newest first, with their state, and the year total from the client area's query
        self::assertStringContainsString('Saved in 2026: ZAR 42.50.', $html);
        self::assertSame(1, preg_match('#<h4>Discounts applied</h4>.*?<tbody>(.*?)</tbody>#s', $html, $m));
        preg_match_all('#<tr><td><a href="invoices.php\?action=edit&amp;id=(\d+)">#', $m[1], $ids);
        self::assertSame(['5', '4', '3', '2', '1', '6', '6'], $ids[1]);
        self::assertStringContainsString('id=5">5</a></td><td>2026-07-01</td><td>1</td><td>5%</td><td>ZAR 850.00</td><td>ZAR 40.00</td><td>no</td><td><span class="label label-default">invoice deleted</span></td>', $html);
        self::assertStringContainsString('id=4">4</a></td><td>2026-06-01</td><td>1</td><td>5%</td><td>ZAR 850.00</td><td>ZAR 30.00</td><td>no</td><td><span class="label label-default">line removed</span></td>', $html);
        self::assertStringContainsString('id=3">3</a></td><td>2026-05-01</td><td>1</td><td>5%</td><td>ZAR 850.00</td><td>ZAR 20.00</td><td>no</td><td><span class="label label-default">invoice Cancelled</span></td>', $html);
        self::assertStringContainsString('id=2">2</a></td><td>2026-04-01</td><td>1</td><td>5%</td><td>ZAR 850.00</td><td>ZAR 10.00</td><td>no</td><td><span class="label label-warning">dry run</span></td>', $html);
        self::assertStringContainsString('id=1">1</a></td><td>2026-03-25</td><td>1</td><td>5%</td><td>ZAR 850.00</td><td>ZAR 42.50</td><td>no</td><td><span class="label label-success">counted</span></td>', $html);
        self::assertStringContainsString('id=6">6</a></td><td>2025-12-25</td><td>2</td><td>10%</td><td>ZAR 850.00</td><td>ZAR 99.00</td><td>yes</td><td><span class="text-muted">counted in 2025</span></td>', $html);
        self::assertStringContainsString('id=6">6</a> <small class="text-muted">line 2</small></td>', $html);
        // footer
        self::assertStringContainsString('<a href="addonmodules.php?module=agencydiscount&amp;action=log&amp;client=131">Change log for this client</a> &middot; <a href="clientsprofile.php?userid=131">Approval on the profile tab</a> &middot; <a href="addonmodules.php?module=agencydiscount">Back to overview</a>', $html);
        self::assertStringNotContainsString(' I ', $html, 'organisation voice');
    }

    public function testAgencyWaitingForItsStartDate(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2026-11-01';
        $html = $this->render(131);
        self::assertStringContainsString('<span class="text-warning">starts 2026-11-01</span>', $html);
        self::assertStringContainsString('<td>2026-10-25</td><td>5</td><td>ZAR 850.00</td><td><span class="text-muted">not yet (before the start date)</span></td>', $html);
        self::assertStringContainsString('<td>2026-11-03</td><td>2</td><td>ZAR 1,955.00</td><td>ZAR 97.75</td>', $html);
    }

    public function testAgencyInACurrencyWithoutTiersAndWithoutRecords(): void
    {
        $html = $this->render(500);
        self::assertStringContainsString('<span class="label label-danger">no tiers for EUR</span>', $html);
        self::assertStringContainsString('<th class="agencydiscount-detail-label">To next tier</th><td>&mdash;</td>', $html);
        self::assertStringContainsString('<td><span class="text-muted">no note</span></td>', $html);
        self::assertStringContainsString('<th class="agencydiscount-detail-label">Figures recalculated</th><td>never</td>', $html);
        self::assertStringContainsString('Saved in 2026: EUR 0.00.', $html);
        self::assertStringContainsString('No invoice has been checked for this agency yet.', $html);
        self::assertStringContainsString('<h4>Counted add-ons</h4><p class="text-muted">None.</p>', $html);
    }

    public function testUnknownClientAndNotAnAgency(): void
    {
        foreach ([600, 999] as $id) {
            $html = $this->render($id);
            self::assertStringContainsString('<div class="alert alert-danger">Client ' . $id . ' is not an approved agency.</div><p><a href="addonmodules.php?module=agencydiscount">Back to overview</a></p>', $html);
        }
    }

    public function testEveryDynamicStringIsEscaped(): void
    {
        $this->repo->clients[131]['name'] = '<b>Evil</b> & Co';
        $this->repo->agencies[131]['approvedBy'] = 'admin:<1>';
        $this->repo->services[1001]['domain'] = '<i>a</i>.example';
        $html = $this->render(131);
        self::assertStringNotContainsString('<b>Evil', $html);
        self::assertStringNotContainsString('<i>a</i>', $html);
        self::assertStringContainsString('admin:&lt;1&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; &amp; Co', $html);
    }

    public function testEveryStringComesFromTheLanguageFile(): void
    {
        $keys = Page::langKeys('agency_');
        self::assertGreaterThan(40, count($keys));
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/addons/agencydiscount/lib/Admin/AgencyTab.php');
        preg_match_all("/Page::lang\\('([a-z_]+)'\\)/", $source, $m);
        self::assertSame([], array_diff(array_unique($m[1]), array_merge($keys, ['col_tier', 'col_spend', 'col_to_next', 'profile_approved_by', 'profile_note', 'never', 'next_invoice_none'])), 'every key the view uses exists');
        self::assertDoesNotMatchRegularExpression("/'<h4>[A-Z]/", $source, 'no hard-coded heading');
    }
}
