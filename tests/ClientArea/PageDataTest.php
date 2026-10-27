<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\ClientArea;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\ClientArea\PageData;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class PageDataTest extends TestCase
{
    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        foreach ([131, 404, 500] as $id) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        }
        $record = ['line' => 0, 'taxed' => false, 'clientId' => 131, 'itemId' => 1, 'tierIndex' => 1, 'percent' => '5.00', 'base' => '850.00', 'amount' => '42.50', 'dryRun' => false, 'createdAt' => '2026-03-25 10:00:00'];
        $this->repo->saveInvoiceRecord(['invoiceId' => 1] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 2, 'amount' => '10.00', 'dryRun' => true] + $record);
        $this->repo->saveInvoiceRecord(['invoiceId' => 3, 'amount' => '99.00', 'createdAt' => '2025-12-25 10:00:00'] + $record);
    }

    /** @param array<string,string> $override */
    private function module(array $override = []): Module
    {
        return new Module($this->repo, Config::fromSettings($override + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
    }

    public function testApprovedAgencyPage(): void
    {
        $vars = PageData::build($this->module(), 131);
        self::assertTrue($vars['approved']);
        self::assertSame('Agency programme', $vars['text']['title']);
        self::assertSame('index.php?m=agencydiscount', $vars['link']);
        self::assertSame('ZAR', $vars['currency']);
        self::assertSame('ZAR 1,012.92', $vars['spend']);
        self::assertTrue($vars['hasTiers']);
        self::assertSame(['index' => 1, 'percent' => '5', 'label' => 'Tier 1 (5% off)'], $vars['tier']);
        self::assertSame([
            ['index' => 1, 'above' => 'ZAR 1,000.00', 'percent' => '5', 'current' => true],
            ['index' => 2, 'above' => 'ZAR 2,000.00', 'percent' => '10', 'current' => false],
            ['index' => 3, 'above' => 'ZAR 3,000.00', 'percent' => '15', 'current' => false],
        ], $vars['tiers']);
        self::assertTrue($vars['hasProgress']);
        self::assertSame(1, $vars['progress']);
        self::assertSame('ZAR 987.09 more a month reaches tier 2 (10% off)', $vars['toNextText']);
        self::assertSame('ZAR 42.50 off the 2026-10-25 invoice of ZAR 850.00', $vars['nextInvoiceText']);
        self::assertSame(['date' => '2026-10-25', 'base' => 'ZAR 850.00', 'discount' => 'ZAR 42.50', 'hasDiscount' => true], $vars['nextInvoice']);
        self::assertSame('ZAR 42.50', $vars['savedThisYear'], 'applied rows of this year only: not the dry run, not last year');
        self::assertSame('Saved in 2026', $vars['savedLabel']);
        self::assertSame(2026, $vars['year']);
        self::assertNull($vars['startsOn']);
        self::assertSame('', $vars['startsText']);
        self::assertFalse($vars['dryRun']);
        self::assertSame(['Legacy', 'Premium Shared', 'Reseller Hosting', 'Cloud'], $vars['counts']['groups'], 'in configured order');
        self::assertSame([], $vars['counts']['products']);
        self::assertSame(['Advanced Email Security', 'Edge Security (WAF, CDN & DDoS)'], $vars['counts']['addons']);
        self::assertStringContainsString('Domains, setup fees', $vars['notCountedText']);
        self::assertStringContainsString('promotion', $vars['notCountedText']);
        self::assertSame(7, $vars['itemCount']);
        self::assertCount(4, $vars['services']);
        self::assertSame(['domain' => 'c.example', 'name' => 'sLegacy-1', 'cycle' => 'Annually', 'amount' => 'ZAR 1,020.00', 'monthly' => 'ZAR 85.00'], $vars['services'][2]);
        self::assertCount(3, $vars['addons']);
        self::assertSame(['domain' => 'c.example', 'name' => 'Advanced Email Security', 'cycle' => 'Annually', 'amount' => 'ZAR 935.00', 'monthly' => 'ZAR 77.92'], $vars['addons'][2]);
        self::assertSame(['services' => 'ZAR 765.00', 'addons' => 'ZAR 247.92', 'all' => 'ZAR 1,012.92'], $vars['totals']);
        self::assertStringNotContainsString(' I ', implode(' ', $vars['text']), 'organisation voice');
    }

    public function testStartDateDryRunAndStackingChangeTheNotes(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $vars = PageData::build($this->module(['dry_run' => 'on', 'stack_with_promotions' => 'on']), 131);
        self::assertSame('2027-01-01', $vars['startsOn']);
        self::assertSame('Your discount starts on 2027-01-01: invoices due before that date are not discounted.', $vars['startsText']);
        self::assertTrue($vars['dryRun']);
        self::assertStringNotContainsString('promotion', $vars['notCountedText']);
        self::assertSame('No discount on the 2026-10-25 invoice of ZAR 850.00', $vars['nextInvoiceText']);
        self::assertFalse($vars['nextInvoice']['hasDiscount'] ?? true);
    }

    public function testTopTierAndCurrencyWithoutTiers(): void
    {
        $top = PageData::build($this->module(), 404);
        self::assertSame('You are at the top tier.', $top['toNextText']);
        self::assertSame(100, $top['progress']);
        self::assertTrue($top['tiers'][2]['current']);

        $euro = PageData::build($this->module(), 500);
        self::assertFalse($euro['hasTiers']);
        self::assertNull($euro['tier']);
        self::assertSame([], $euro['tiers']);
        self::assertFalse($euro['hasProgress']);
        self::assertSame('The programme has no tiers for EUR yet.', $euro['toNextText']);
        self::assertSame('The programme has no tiers for EUR yet.', $euro['noTiersText']);
        self::assertSame('EUR 0.00', $euro['savedThisYear']);
    }

    public function testBelowTheFirstTierAndNoInvoiceInWindow(): void
    {
        $this->repo->services[2001]['amount'] = '400.00';
        $this->repo->services[2001]['nextInvoiceDate'] = '2027-03-01';
        $vars = PageData::build($this->module(), 404);
        self::assertNull($vars['tier']);
        self::assertSame('ZAR 600.01 more a month reaches tier 1 (5% off)', $vars['toNextText']);
        self::assertSame('No invoice in the next 35 days', $vars['nextInvoiceText']);
        self::assertNull($vars['nextInvoice']);
    }

    public function testNotApprovedMissingOrAnonymous(): void
    {
        $module = $this->module();
        foreach ([600, null, 0, 999] as $clientId) {
            $vars = PageData::build($module, $clientId);
            self::assertFalse($vars['approved']);
            self::assertSame('This account is not part of the agency programme.', $vars['text']['not_member']);
            self::assertArrayNotHasKey('spend', $vars);
            self::assertSame('', PageData::panelHtml($vars));
        }
    }

    public function testPanelHtml(): void
    {
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
        $this->repo->clients[131]['name'] = '<b>x</b>';
        $html = PageData::panelHtml(PageData::build($this->module(['dry_run' => 'on']), 131));
        self::assertStringContainsString('<span class="badge badge-success">Tier 1 (5% off)</span>', $html);
        self::assertStringContainsString('Your tier', $html);
        self::assertStringContainsString('ZAR 1,012.92', $html);
        self::assertStringContainsString('Saved in 2026', $html);
        self::assertStringContainsString('No discount on the 2026-10-25 invoice', $html);
        self::assertStringContainsString('Your discount starts on 2027-01-01', $html);
        self::assertStringContainsString('being set up', $html);
        $euro = PageData::panelHtml(PageData::build($this->module(), 500));
        self::assertStringContainsString('<span class="badge badge-secondary">The programme has no tiers for EUR yet.</span>', $euro);
    }
}
