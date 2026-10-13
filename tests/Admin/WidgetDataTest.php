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
        $data = WidgetData::build($this->module(['dry_run' => 'on']));
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
        $data = WidgetData::build($this->module());
        self::assertSame([777], $data['missing']);
        self::assertCount(3, $data['rows']);
        foreach ([131, 404, 500] as $id) {
            $this->repo->agencies[$id]['calculatedAt'] = null;
        }
        self::assertNull(WidgetData::build($this->module())['lastRecalculation']);
    }

    public function testRenderShowsTilesRowsModeAndLink(): void
    {
        $html = WidgetData::render(WidgetData::build($this->module(['dry_run' => 'on'])));
        self::assertStringContainsString('widget-content-padded', $html);
        self::assertStringContainsString('<div class="agencydiscount-widget-value">3</div><div class="agencydiscount-widget-label">Agencies</div>', $html);
        self::assertStringContainsString('EUR 300.00', $html);
        self::assertStringContainsString('ZAR 7,012.92', $html);
        self::assertStringContainsString('ZAR 900.00', $html);
        self::assertStringContainsString('Next 35 days', $html);
        self::assertStringContainsString('<span class="label label-warning">Dry-run</span>', $html);
        self::assertStringContainsString('<a href="clientssummary.php?userid=131">Agency One</a>', $html);
        self::assertStringContainsString('<span class="label label-success">Tier 1 (5%)</span>', $html);
        self::assertStringContainsString('<span class="label label-danger">no tiers for EUR</span>', $html);
        self::assertStringContainsString('starts 2027-01-01', $html);
        self::assertStringContainsString('2026-10-20: ZAR 900.00', $html);
        self::assertStringContainsString('Last cron check: 2026-10-12 03:00:00', $html);
        self::assertStringContainsString('<a href="addonmodules.php?module=agencydiscount">Open Agency Programme</a>', $html);
        self::assertStringNotContainsString('<span class="label label-success">Live</span>', $html);
        self::assertStringContainsString('<span class="label label-success">Live</span>', WidgetData::render(WidgetData::build($this->module())));
    }

    public function testRenderWithoutAgenciesAndWithMissingClients(): void
    {
        $this->repo->agencies = [];
        $html = WidgetData::render(WidgetData::build($this->module()));
        self::assertStringContainsString('No approved agencies yet.', $html);
        self::assertStringContainsString('Last cron check: never', $html);

        $this->repo->saveAgency(['clientId' => 777, 'approved' => true, 'updatedAt' => '2026-10-01 00:00:00']);
        self::assertStringContainsString('Client 777 no longer exists in WHMCS.', WidgetData::render(WidgetData::build($this->module())));
    }

    public function testNamesAreEscaped(): void
    {
        $this->repo->clients[131]['name'] = '<b>Evil</b>';
        $html = WidgetData::render(WidgetData::build($this->module()));
        self::assertStringNotContainsString('<b>Evil', $html);
        self::assertStringContainsString('&lt;b&gt;Evil', $html);
    }
}
