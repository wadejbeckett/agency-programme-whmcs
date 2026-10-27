<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testInstallAndUninstallRunOneStatementPerTable(): void
    {
        $repo = new ArrayRepository();
        Schema::install($repo);
        self::assertCount(3, $repo->executed);
        foreach (Schema::TABLES as $i => $table) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS ' . $table, $repo->executed[$i]);
        }
        $repo->executed = [];
        Schema::uninstall($repo);
        self::assertSame(array_map(static fn (string $t) => 'DROP TABLE IF EXISTS ' . $t, Schema::TABLES), $repo->executed);
    }

    public function testClientsTableHasEffectiveFromAfterApprovedAt(): void
    {
        $sql = Schema::createStatements()[0];
        self::assertMatchesRegularExpression('/approved_at DATETIME NULL,\s+effective_from DATE NULL,\s+currency/', $sql);
    }

    public function testInvoicesTableHoldsOneRowPerDiscountLine(): void
    {
        $sql = Schema::createStatements()[2];
        self::assertStringContainsString('id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,', $sql);
        self::assertStringContainsString('invoice_id INT NOT NULL,', $sql);
        self::assertStringNotContainsString('invoice_id INT NOT NULL PRIMARY KEY', $sql);
        self::assertStringContainsString('line TINYINT NOT NULL DEFAULT 0,', $sql);
        self::assertStringContainsString('taxed TINYINT(1) NOT NULL DEFAULT 0,', $sql);
        self::assertStringContainsString('UNIQUE KEY idx_invoice_line (invoice_id, line)', $sql, 'the claim on line 0 stays first-writer-wins');
    }

    public function testUpgradeStatementsByPreviousVersion(): void
    {
        $from020 = Schema::upgradeStatements('0.2.0');
        self::assertSame([
            'ALTER TABLE mod_agencydiscount_invoices DROP PRIMARY KEY, ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST, ADD COLUMN line TINYINT NOT NULL DEFAULT 0 AFTER invoice_id, ADD COLUMN taxed TINYINT(1) NOT NULL DEFAULT 0 AFTER item_id, ADD UNIQUE KEY idx_invoice_line (invoice_id, line)',
            'UPDATE mod_agencydiscount_invoices r JOIN tblinvoiceitems i ON i.id = r.item_id SET r.taxed = i.taxed',
        ], $from020);
        self::assertSame($from020, Schema::upgradeStatements('0.1.0'));
        self::assertSame([], Schema::upgradeStatements('0.2.1'));
        self::assertSame([], Schema::upgradeStatements('0.3.0'));
    }

    /** Every column and key the fresh table has, the upgraded table gets, so both installs end with the same shape. */
    public function testUpgradeReachesTheCreateTableShape(): void
    {
        $create = Schema::createStatements()[2];
        $alter = Schema::upgradeStatements('0.2.0')[0];
        foreach (['id INT NOT NULL AUTO_INCREMENT PRIMARY KEY', 'line TINYINT NOT NULL DEFAULT 0', 'taxed TINYINT(1) NOT NULL DEFAULT 0', 'UNIQUE KEY idx_invoice_line (invoice_id, line)'] as $part) {
            self::assertStringContainsString($part, $create);
            self::assertStringContainsString($part, $alter);
        }
    }
}
