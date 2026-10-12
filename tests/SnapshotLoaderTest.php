<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\SnapshotLoader;
use PHPUnit\Framework\TestCase;

final class SnapshotLoaderTest extends TestCase
{
    public function testLoadsFixtureSnapshotIntoRepository(): void
    {
        $repo = SnapshotLoader::load(__DIR__ . '/fixtures/snapshot');
        self::assertSame('Agency One', $repo->client(131)['name'] ?? null);
        self::assertSame('ZAR', $repo->client(131)['currency'] ?? null);
        self::assertSame('EUR', $repo->client(500)['currency'] ?? null);
        self::assertCount(6, $repo->services(131));
        self::assertNull($repo->services(131)[3]['nextInvoiceDate']);
        self::assertSame(23, $repo->services(131)[0]['groupId']);
        self::assertSame('sPremium-1', $repo->services(131)[0]['productName']);
        self::assertCount(5, $repo->addons(131));
        self::assertSame('b.example', $repo->addons(131)[1]['domain']);
        self::assertSame('85.00', $repo->addons(131)[1]['recurring']);
        self::assertCount(8, $repo->invoiceItems(9001));
        self::assertSame('-17.00', $repo->invoiceItems(9001)[6]['amount']);
        self::assertSame(['ZAR', 'EUR'], $repo->currencyCodes());
    }

    public function testNameFallbackNumbersAndLookups(): void
    {
        $repo = SnapshotLoader::load(__DIR__ . '/fixtures/snapshot');
        self::assertSame(['id' => 404, 'name' => 'Agency Two', 'currency' => 'ZAR', 'currencyId' => 1], $repo->client(404));
        self::assertSame('Retail Client', $repo->client(600)['name'] ?? null);
        self::assertSame('1200.00', $repo->invoiceItems(9001)[7]['amount']);
        self::assertSame(['id' => 9001, 'clientId' => 131, 'status' => 'Unpaid', 'dueDate' => '2026-10-25', 'paymentMethod' => 'banktransfer'], $repo->invoice(9001));
        self::assertSame([23 => 'Premium Shared'], $repo->lookupNames('group', [23, 999]));
        self::assertSame([113 => 'sPremium-1'], $repo->lookupNames('product', [113]));
        self::assertSame([15 => 'Edge Security'], $repo->lookupNames('addon', [15]));
        self::assertSame(
            ['id' => 1001, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'a.example', 'amount' => '170.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0],
            $repo->services(131)[0],
        );
        self::assertSame(8, $repo->services(131)[5]['promoId']);
    }

    public function testUnknownProductAddonAndCurrencyGetPlaceholders(): void
    {
        $dir = $this->tempDir();
        try {
            file_put_contents($dir . '/tblclients.json', '[{"id":7,"firstname":"A","lastname":"B","companyname":"","currency":9}]');
            file_put_contents($dir . '/tblhosting.json', '[{"id":70,"userid":7,"packageid":555,"domain":null,"amount":12.5,"billingcycle":"Monthly","domainstatus":"Active","nextinvoicedate":"","promoid":null}]');
            file_put_contents($dir . '/tblhostingaddons.json', '[{"id":71,"userid":0,"hostingid":404404,"addonid":66,"recurring":"1.00","billingcycle":"Monthly","status":"Active","nextinvoicedate":null}]');
            $repo = SnapshotLoader::load($dir);
            self::assertSame(['id' => 7, 'name' => 'A B', 'currency' => '', 'currencyId' => 9], $repo->client(7));
            self::assertSame(
                ['id' => 70, 'productId' => 555, 'groupId' => 0, 'productName' => 'product #555', 'domain' => '', 'amount' => '12.50', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => null, 'promoId' => 0],
                $repo->services(7)[0],
            );
            self::assertSame([], $repo->addons(7), 'an add-on with userid 0 and no parent service belongs to nobody');
            self::assertSame([], $repo->currencyCodes());
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    public function testMissingFilesAreEmptyAndBadJsonIsRejected(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/tblclients.json', '{"not":"a list"}');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('snapshot: tblclients.json is not a JSON array');
            SnapshotLoader::load($dir);
        } finally {
            unlink($dir . '/tblclients.json');
            rmdir($dir);
        }
    }

    public function testEmptyJsonObjectAndNonObjectRowsAreRejected(): void
    {
        $dir = $this->tempDir();
        try {
            foreach (['{}' => 'is not a JSON array', '[[1,2]]' => 'row 1 is not an object'] as $json => $message) {
                file_put_contents($dir . '/tblhosting.json', $json);
                try {
                    SnapshotLoader::load($dir);
                    self::fail('expected a RuntimeException for ' . $json);
                } catch (\RuntimeException $e) {
                    self::assertSame('snapshot: tblhosting.json ' . $message, $e->getMessage());
                }
            }
        } finally {
            unlink($dir . '/tblhosting.json');
            rmdir($dir);
        }
    }

    public function testMissingDirectoryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        SnapshotLoader::load(sys_get_temp_dir() . '/agencydiscount-no-such-dir-' . bin2hex(random_bytes(4)));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/agencydiscount-snap-' . bin2hex(random_bytes(4));
        mkdir($dir);
        return $dir;
    }
}
