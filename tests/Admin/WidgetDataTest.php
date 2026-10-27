<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\WidgetData;
use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class WidgetDataTest extends TestCase
{
    private ArrayRepository $repo;

    protected function setUp(): void
    {
        $this->repo = Fixtures::agencyRepo();
        $this->repo->settings = Fixtures::settings();
        foreach ([131 => '2026-10-10 03:00:00', 404 => '2026-10-12 03:00:00', 500 => '2026-10-11 03:00:00'] as $id => $calculatedAt) {
            $this->repo->saveAgency(['clientId' => $id, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'calculatedAt' => $calculatedAt, 'updatedAt' => '2026-10-01 00:00:00']);
        }
        $this->repo->agencies[131]['effectiveFrom'] = '2027-01-01';
    }

    /** @param array<string,string> $override */
    private function module(array $override = []): Module
    {
        return new Module($this->repo, Config::fromSettings($override + Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
    }

    public function testBuildGathersTheNumbers(): void
    {
        $data = WidgetData::build($this->module(['dry_run' => 'on']), 1);
        self::assertSame(3, $data['agencies']);
        self::assertSame(2, $data['atTier']);
        self::assertSame(1, $data['waiting']);
        self::assertSame(['EUR' => '300.00', 'ZAR' => '7012.92'], $data['spend']);
        self::assertSame(['EUR' => '0.00', 'ZAR' => '900.00'], $data['discount']);
        self::assertTrue($data['dryRun']);
        self::assertSame('2026-10-12 03:00:00', $data['lastRecalculation']);
        self::assertSame([], $data['missing']);
        self::assertCount(3, $data['rows']);
        self::assertSame([
            'clientId' => 131,
            'name' => 'Agency One',
            'currency' => 'ZAR',
            'spend' => '1012.92',
            'tier' => ['index' => 1, 'percent' => '5.00'],
            'hasTiers' => true,
            'nextInvoice' => ['date' => '2026-10-25', 'base' => '850.00', 'discount' => '0.00'],
            'startsOn' => '2027-01-01',
        ], $data['rows'][0]);
        self::assertNull($data['rows'][2]['tier']);
        self::assertFalse($data['rows'][2]['hasTiers']);
        self::assertSame('900.00', $data['rows'][1]['nextInvoice']['discount'] ?? null);
    }

    public function testBuildNotesMissingClientsAndLastRecalculation(): void
    {
        $this->repo->saveAgency(['clientId' => 777, 'approved' => true, 'updatedAt' => '2026-10-01 00:00:00']);
        $data = WidgetData::build($this->module(), 1);
        self::assertSame([777], $data['missing']);
        self::assertCount(3, $data['rows']);
        foreach ([131, 404, 500] as $id) {
            $this->repo->agencies[$id]['calculatedAt'] = null;
        }
        self::assertNull(WidgetData::build($this->module(), 1)['lastRecalculation']);
    }

    public function testRenderShowsTilesRowsModeAndLink(): void
    {
        $html = WidgetData::render(WidgetData::build($this->module(['dry_run' => 'on']), 1));
        self::assertStringContainsString('<div class="note">Agencies</div><div class="number">3 <span class="unit">2 at a tier</span></div>', $html);
        self::assertStringContainsString('<div class="number">300.00 <span class="unit">EUR</span></div><div class="number">7,012.92 <span class="unit">ZAR</span></div>', $html);
        self::assertStringContainsString('<div class="number">900.00 <span class="unit">ZAR</span></div>', $html);
        self::assertStringContainsString('<div class="note">Discount in 35 days</div>', $html, 'two currencies: the codes are on the number lines');
        self::assertStringContainsString('<div class="note">Spend per month</div>', $html);
        self::assertStringContainsString('<div class="icon-holder text-center color-orange"><a href="addonmodules.php?module=agencydiscount"><i class="pe-7s-clock" aria-hidden="true"></i></a></div><div class="data"><div class="note">Mode</div><div class="number"><span class="label label-warning">Dry-run</span></div>', $html);
        self::assertStringContainsString('<a href="clientssummary.php?userid=131" title="Agency One">Agency One</a>', $html);
        self::assertStringContainsString('<span class="label label-success">Tier 1 (5%)</span>', $html);
        self::assertStringContainsString('<span class="label label-danger">no tiers for EUR</span>', $html);
        self::assertStringContainsString('<td class="text-right agencydiscount-widget-figures">ZAR 6,000.00<div class="agencydiscount-widget-sub">900.00 on 2026-10-20</div></td>', $html);
        self::assertStringContainsString('<span class="text-warning">starts 2027-01-01</span>', $html, 'agency one has not started and its next invoice gets nothing');
        self::assertStringContainsString('no discount on 2026-10-20', $html, 'the EUR agency has no tiers');
        self::assertStringContainsString('<div class="footer agencydiscount-widget-footer"><span>Last cron check: 2026-10-12 03:00:00</span><a href="addonmodules.php?module=agencydiscount">Open Agency Programme</a></div>', $html);
        self::assertStringNotContainsString('<span class="label label-success">Live</span>', $html);
        $live = WidgetData::render(WidgetData::build($this->module(), 1));
        $this->repo->agencies = array_diff_key($this->repo->agencies, [500 => true]);
        $oneCurrency = WidgetData::render(WidgetData::build($this->module(), 1));
        self::assertStringContainsString('<div class="note">Spend per month, ZAR</div><div class="number">7,012.92</div>', $oneCurrency, 'one currency: its code is on the note');
        self::assertStringContainsString('<div class="note">Discount in 35 days, ZAR</div><div class="number">900.00</div>', $oneCurrency);
        self::assertStringContainsString('<i class="pe-7s-check" aria-hidden="true"></i>', $live);
        self::assertStringContainsString('<div class="number"><span class="label label-success">Live</span></div>', $live);
    }

    public function testRenderUsesWhmcsOwnWidgetMarkup(): void
    {
        $html = WidgetData::render(WidgetData::build($this->module(), 1));
        // The figures are an icon-stats block (the Support widget's pattern, styled by both admin
        // themes for any widget), four items in two Bootstrap columns.
        self::assertStringStartsWith('<div class="icon-stats agencydiscount-widget"><div class="row"><div class="col-sm-6"><div class="item"><div class="icon-holder text-center color-green">', $html);
        self::assertSame(4, substr_count($html, '<div class="col-sm-6"><div class="item">'));
        // The agency list sits in the theme's own scrolling list box; nothing overrides a theme rule.
        self::assertStringContainsString('<div class="widget-content-padded agencydiscount-widget-list"><table class="table table-condensed agencydiscount-widget-table"><thead><tr><th scope="col">Agency</th><th scope="col" class="text-right">Spend, next discount</th></tr></thead>', $html);
        self::assertSame(3, substr_count($html, '<td class="agencydiscount-widget-name">'));
        self::assertStringNotContainsString('max-height', $html);
        self::assertStringNotContainsString('.dashboard-panel-item', $html);
        self::assertStringNotContainsString('.widget-content-padded', $html);
        self::assertStringNotContainsString('table-responsive', $html);
        self::assertStringNotContainsString('col-sm-3', $html);
        self::assertSame(1, substr_count($html, '<style>'));
        self::assertStringContainsString('var(--border-subtle,#eee)', $html, 'Iris token with a Blend fallback');
    }

    public function testUnavailableIsOneLine(): void
    {
        self::assertSame('<div class="widget-content-padded">Agency Programme: unavailable (see activity log)</div>', WidgetData::unavailable());
    }

    public function testRenderWithoutAgenciesAndWithMissingClients(): void
    {
        $this->repo->agencies = [];
        $html = WidgetData::render(WidgetData::build($this->module(), 1));
        self::assertStringContainsString('No approved agencies yet.', $html);
        self::assertStringContainsString('Last cron check: never', $html);

        $this->repo->saveAgency(['clientId' => 777, 'approved' => true, 'updatedAt' => '2026-10-01 00:00:00']);
        self::assertStringContainsString('Client 777 no longer exists in WHMCS.', WidgetData::render(WidgetData::build($this->module(), 1)));
    }

    public function testOnlyRolesOnTheAddonAccessListSeeTheFigures(): void
    {
        $repo = $this->repo;
        self::assertTrue(WidgetData::roleAllowed($repo, 1), 'admin 1 is in role 1, listed in access "1,2"');
        self::assertFalse(WidgetData::roleAllowed($repo, 2), 'admin 2 is in role 3, not listed');
        self::assertFalse(WidgetData::roleAllowed($repo, 9), 'unknown admin');
        self::assertFalse(WidgetData::roleAllowed($repo, null));
        self::assertFalse(WidgetData::roleAllowed($repo, 0));
        $repo->settings['access'] = ' 3 , 1 ';
        self::assertTrue(WidgetData::roleAllowed($repo, 2));
        $repo->settings['access'] = '';
        self::assertFalse(WidgetData::roleAllowed($repo, 1));
        unset($repo->settings['access']);
        self::assertFalse(WidgetData::roleAllowed($repo, 1), 'no access list means no role');

        $repo->settings['access'] = '1';
        self::assertSame(['denied' => true], WidgetData::build($this->module(), 2));
        $html = WidgetData::render(['denied' => true]);
        self::assertStringContainsString('not available to your admin role', $html);
        self::assertStringNotContainsString('ZAR', $html);
        self::assertArrayHasKey('rows', WidgetData::build($this->module(), 1));
    }

    public function testNamesAreEscaped(): void
    {
        $this->repo->clients[131]['name'] = '<b>Evil</b>';
        $html = WidgetData::render(WidgetData::build($this->module(), 1));
        self::assertStringNotContainsString('<b>Evil', $html);
        self::assertStringContainsString('&lt;b&gt;Evil', $html);
    }
}
