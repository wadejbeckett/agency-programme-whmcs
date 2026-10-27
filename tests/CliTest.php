<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Cli;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $settings;

    protected function setUp(): void
    {
        $this->settings = (string) tempnam(sys_get_temp_dir(), 'ad-settings');
        file_put_contents($this->settings, json_encode([
            'tiers' => '{"ZAR":[{"above":1000,"percent":5},{"above":2000,"percent":10},{"above":3000,"percent":15}]}',
            'product_groups' => '1,23,3,6', 'addons' => '12,15', 'statuses' => 'Active', 'dry_run' => '',
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->settings);
    }

    /**
     * Named runCli because TestCase::run() is final.
     *
     * @param list<string> $args
     * @return array{int, list<string>}
     */
    private function runCli(array $args): array
    {
        $lines = [];
        $code = Cli::run(array_merge(['agency-discount'], $args), static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        return [$code, $lines];
    }

    public function testRehearseReportsSpendTierAndUpcomingInvoices(): void
    {
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--approve', '131,500', '--days', '35', '--today', '2026-10-12']);
        self::assertSame(0, $code, implode("\n", $lines));
        $text = implode("\n", $lines);
        self::assertStringContainsString('Client 131 Agency One (ZAR): qualifying monthly spend 1,012.92, tier 1 (5%), 4 services + 3 add-ons, to next tier 987.09', $text);
        self::assertStringContainsString('  2026-10-25: 5 items, base 850.00, discount 42.50', $text);
        self::assertStringContainsString('  2026-11-03: 2 items, base 1,955.00, discount 97.75', $text);
        self::assertStringContainsString('Client 500 Euro Agency (EUR): qualifying monthly spend 300.00, no tiers configured for EUR', $text);
    }

    public function testRehearseReportsNoTierTopTierAndMissingClients(): void
    {
        file_put_contents($this->settings, json_encode([
            'tiers' => '{"ZAR":[{"above":1000,"percent":5}]}',
            'product_groups' => '1,23,3,6', 'addons' => '12,15',
        ]));
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--approve', '131,600,777', '--days', '20', '--today', '2026-10-12']);
        self::assertSame(0, $code, implode("\n", $lines));
        self::assertSame([
            'Rehearsal on 2026-10-12, upcoming invoices to 2026-11-01. Dry run: nothing is written.',
            'Client 131 Agency One (ZAR): qualifying monthly spend 1,012.92, tier 1 (5%), 4 services + 3 add-ons, top tier',
            '  2026-10-25: 5 items, base 850.00, discount 42.50',
            'Client 600 Retail Client (ZAR): qualifying monthly spend 170.00, no tier, 1 services + 0 add-ons, to next tier 830.01',
            '  2026-10-25: 1 items, base 170.00, discount 0.00',
            'Client 777: not found in snapshot',
        ], $lines);
    }

    public function testRehearseWithoutApprovalsSaysSo(): void
    {
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--today', '2026-10-12']);
        self::assertSame(0, $code);
        self::assertStringContainsString('No clients approved', implode("\n", $lines));
    }

    public function testRehearseInvoiceIsAlwaysDryRun(): void
    {
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--approve', '131', '--invoice', '9001']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Invoice 9001: dry_run - 5% of 595.00 = 29.75 on 3 lines, line text "Agency discount 5% on 3 qualifying items"', implode("\n", $lines));
    }

    public function testRehearseInvoiceMixingTaxTreatmentsShowsBothLines(): void
    {
        $snapshot = sys_get_temp_dir() . '/ad-snapshot-' . bin2hex(random_bytes(4));
        mkdir($snapshot);
        foreach (glob(__DIR__ . '/fixtures/snapshot/*.json') ?: [] as $file) {
            copy($file, $snapshot . '/' . basename($file));
        }
        $items = json_decode((string) file_get_contents($snapshot . '/tblinvoiceitems.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($items);
        $items[0]['taxed'] = 1;
        file_put_contents($snapshot . '/tblinvoiceitems.json', json_encode($items));
        try {
            [$code, $lines] = $this->runCli(['rehearse', '--snapshot', $snapshot, '--settings', $this->settings, '--approve', '131', '--invoice', '9001']);
        } finally {
            array_map('unlink', glob($snapshot . '/*.json') ?: []);
            rmdir($snapshot);
        }
        self::assertSame(0, $code);
        self::assertSame([
            'Invoice 9001: dry_run - 5% of 595.00 = 29.75 on 3 lines, as 2 discount lines, one per tax treatment',
            '  untaxed: 21.25 of 425.00 on 2 lines, line text "Agency discount 5% on 2 qualifying items"',
            '  taxed: 8.50 of 170.00 on 1 lines, line text "Agency discount 5% on 1 qualifying item"',
        ], array_slice($lines, -3));
    }

    public function testRehearseInvoiceSkipReasons(): void
    {
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--approve', '131', '--invoice', '9004']);
        self::assertSame(0, $code);
        self::assertContains('Invoice 9004: skipped (not_approved)', $lines);
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--invoice', '12345']);
        self::assertSame(0, $code);
        self::assertContains('Invoice 12345: skipped (invoice_not_found)', $lines);
    }

    public function testUsageAndErrors(): void
    {
        [$code, $lines] = $this->runCli(['rehearse']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Usage:', implode("\n", $lines));
        [$code] = $this->runCli(['help']);
        self::assertSame(0, $code);
        [$code] = $this->runCli([]);
        self::assertSame(2, $code);
        [$code] = $this->runCli(['frobnicate']);
        self::assertSame(2, $code);
        [$code] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--days', 'many']);
        self::assertSame(2, $code);
        [$code] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--approve', '131,x']);
        self::assertSame(2, $code);
        [$code] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--today', '2026-02-30']);
        self::assertSame(2, $code);
        [$code] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--bogus', '1']);
        self::assertSame(2, $code);
        [$code] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings']);
        self::assertSame(2, $code);
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', __DIR__ . '/no-such-settings.json']);
        self::assertSame(2, $code);
        self::assertStringContainsString('settings', implode("\n", $lines));
        file_put_contents($this->settings, json_encode(['tiers' => '{']));
        [$code, $lines] = $this->runCli(['rehearse', '--snapshot', __DIR__ . '/fixtures/snapshot', '--settings', $this->settings, '--approve', '131']);
        self::assertSame(1, $code);
        self::assertStringContainsString('tiers: invalid JSON', implode("\n", $lines));
    }
}
