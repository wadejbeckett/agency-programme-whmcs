<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Tests\Support\WhmcsCalls;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testFakeWhmcsRecordsHooksAndActivity(): void
    {
        WhmcsCalls::reset();
        add_hook('InvoiceCreation', 1, static fn () => null);
        logActivity('hello', 7);
        self::assertSame('InvoiceCreation', WhmcsCalls::$hooks[0]['hook']);
        self::assertSame(['message' => 'hello', 'clientId' => 7], WhmcsCalls::$activity[0]);
    }

    public function testWhmcsJsonIsValid(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../modules/addons/agencydiscount/whmcs.json'), true);
        self::assertSame('agencydiscount', $json['name']);
        self::assertSame('AGPL-3.0-or-later', $json['license']);
    }
}
