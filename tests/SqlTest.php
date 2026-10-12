<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testPlaceholders(): void
    {
        self::assertSame('?,?,?', Sql::placeholders([1, 2, 3]));
        self::assertSame('NULL', Sql::placeholders([]));
    }

    public function testEveryConstantHasExpectedColumns(): void
    {
        $constants = (new \ReflectionClass(Sql::class))->getConstants();
        self::assertSame(array_keys($constants), array_keys(Sql::expectedColumns()));
        self::assertSame(['id', 'firstname', 'lastname', 'companyname', 'currency_id', 'currency'], Sql::expectedColumns()['CLIENT']);
        self::assertSame(['id', 'type', 'relid', 'description', 'amount', 'taxed'], Sql::expectedColumns()['INVOICE_ITEMS']);
        self::assertSame([], Sql::expectedColumns()['CLAIM_INVOICE']);
    }
}
