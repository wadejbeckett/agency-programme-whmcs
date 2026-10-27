<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\LogEntry;
use PHPUnit\Framework\TestCase;

final class LogEntryTest extends TestCase
{
    private const CLIENTS = [131 => ['name' => 'Agency One', 'currency' => 'ZAR']];

    /** @param array<string,mixed> $detail */
    private static function entry(string $event, array $detail, ?int $clientId = 131, ?int $invoiceId = null, string $actor = 'admin:1'): string
    {
        return LogEntry::sentence(['id' => 1, 'clientId' => $clientId, 'invoiceId' => $invoiceId, 'event' => $event, 'detail' => $detail, 'actor' => $actor, 'createdAt' => '2026-10-12 09:00:00'], self::CLIENTS);
    }

    public function testApprovalEvents(): void
    {
        self::assertSame('Agency One approved as an agency by admin:1.', self::entry('approved', ['note' => '']));
        self::assertSame('Agency One approved as an agency by admin:1 (note: "Studio contact").', self::entry('approved', ['note' => 'Studio contact']));
        self::assertSame('Agency One\'s agency approval revoked by admin:2.', self::entry('revoked', ['note' => ''], 131, null, 'admin:2'));
        self::assertSame('Note for Agency One changed to "b".', self::entry('note_changed', ['from' => 'a', 'to' => 'b']));
        self::assertSame('Discount start date for Agency One set to 2027-01-01.', self::entry('effective_from_changed', ['from' => null, 'to' => '2027-01-01']));
        self::assertSame('Discount start date for Agency One cleared.', self::entry('effective_from_changed', ['from' => '2027-01-01', 'to' => null]));
        self::assertSame('Invalid discount start date "2027-13-40" for Agency One ignored.', self::entry('effective_from_invalid', ['value' => '2027-13-40']));
    }

    public function testTierEvents(): void
    {
        self::assertSame('Agency One moved from no tier to tier 1 (5%) on monthly spend ZAR 1,012.92.', self::entry('tier_changed', ['from' => null, 'to' => 1, 'fromPercent' => null, 'toPercent' => '5.00', 'spend' => '1012.92', 'currency' => 'ZAR']));
        self::assertSame('Agency One moved from tier 2 (10%) to no tier on monthly spend ZAR 900.00.', self::entry('tier_changed', ['from' => 2, 'to' => null, 'fromPercent' => '10.00', 'toPercent' => null, 'spend' => '900.00', 'currency' => 'ZAR']));
        self::assertSame('Agency One has no tier: no tiers are configured for ZAR (monthly spend ZAR 300.00).', self::entry('no_tier', ['currency' => 'ZAR', 'spend' => '300.00']));
    }

    public function testInvoiceEvents(): void
    {
        $detail = ['base' => '850.00', 'amount' => '42.50', 'percent' => '5.00', 'tierIndex' => 1, 'countedLines' => 5, 'description' => 'x', 'source' => 'autogen'];
        self::assertSame('Discount of ZAR 42.50 (5%) added to invoice #9001 for Agency One.', self::entry('applied', $detail + ['itemId' => 12], 131, 9001, 'system'));
        self::assertSame('Dry run: ZAR 42.50 (5%) would have come off invoice #9001 for Agency One.', self::entry('dry_run', $detail + ['forced' => false], 131, 9001, 'system'));
        $lines = ['lines' => [['taxed' => 0, 'base' => '425.00', 'amount' => '21.25', 'countedLines' => 2, 'description' => 'x', 'itemId' => 12], ['taxed' => 1, 'base' => '170.00', 'amount' => '8.50', 'countedLines' => 1, 'description' => 'y', 'itemId' => 13]]];
        self::assertSame('Discount of ZAR 29.75 (5%) added to invoice #9001 for Agency One, as 2 lines (untaxed ZAR 21.25, taxed ZAR 8.50).', self::entry('applied', ['amount' => '29.75', 'percent' => '5.00'] + $lines, 131, 9001, 'system'));
        self::assertSame('Dry run: ZAR 29.75 (5%) would have come off invoice #9001 for Agency One, as 2 lines (untaxed ZAR 21.25, taxed ZAR 8.50).', self::entry('dry_run', ['amount' => '29.75', 'percent' => '5.00'] + $lines, 131, 9001, 'system'));
        self::assertSame('Invoice #9001 for Agency One was already handled; nothing changed.', self::entry('already_processed', ['dryRun' => true], 131, 9001));
        self::assertSame('Invoice #9001 for Agency One is due before the discount start date 2027-01-01; no discount.', self::entry('not_yet_effective', ['effectiveFrom' => '2027-01-01', 'dueDate' => '2026-10-25', 'today' => '2026-10-12', 'source' => 'autogen'], 131, 9001));
        self::assertSame('Invoice #9001 for Agency One had no discountable lines.', self::entry('no_qualifying_lines', ['itemTypes' => ['Domain']], 131, 9001));
        self::assertSame('Discount on invoice #9001 for Agency One rounded to nothing.', self::entry('zero_amount', ['base' => '0.01', 'percent' => '5.00'], 131, 9001));
        self::assertSame('Invoice #42 was not found.', self::entry('invoice_not_found', [], null, 42));
    }

    public function testAdministrationEvents(): void
    {
        self::assertSame('Settings changed by admin:1: tiers, addons.', self::entry('settings_changed', ['changed' => ['tiers' => ['from' => '{}', 'to' => '{"ZAR":[]}'], 'addons' => ['from' => '', 'to' => '12']]], null));
        self::assertSame('Switched to live mode by admin:1.', self::entry('mode_changed', ['from' => 'dry-run', 'to' => 'live'], null));
        self::assertSame('Client 999 no longer exists in WHMCS.', self::entry('client_missing', ['message' => 'Client 999 not found'], 999));
        self::assertSame('Recalculating client 999 failed: database gone.', self::entry('recalculate_failed', ['message' => 'database gone'], 999));
    }

    public function testUnknownEventAndUnknownClientFallBack(): void
    {
        self::assertSame('something_else for client 999 by admin:1: {"k":"v"}', self::entry('something_else', ['k' => 'v'], 999));
        self::assertSame('Client 999 approved as an agency by admin:1.', self::entry('approved', ['note' => ''], 999));
    }
}
