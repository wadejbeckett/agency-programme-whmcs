<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Repository;
use AgencyDiscount\InvoiceDiscounter;
use AgencyDiscount\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testPlaceholders(): void
    {
        self::assertSame('?,?,?', Sql::placeholders([1, 2, 3]));
        self::assertSame('NULL', Sql::placeholders([]));
    }

    public function testTheSavedTotalUsesTheOneStatusListAndTheItemType(): void
    {
        preg_match('/i\.status IN \(([^)]*)\)/', Sql::APPLIED_DISCOUNT_TOTAL, $m);
        self::assertSame(Repository::SAVED_STATUSES, array_map(static fn (string $s): string => trim($s, " '"), explode(',', $m[1])), 'the SQL list is Repository::SAVED_STATUSES');
        self::assertSame(2, substr_count(Sql::APPLIED_DISCOUNT_TOTAL . Sql::CLIENT_INVOICE_RECORDS, "ii.type = '" . InvoiceDiscounter::ITEM_TYPE . "'"));
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
