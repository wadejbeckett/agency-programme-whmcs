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
}
