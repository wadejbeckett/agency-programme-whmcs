<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ArrayRepositoryTest extends TestCase
{
    public function testReadsFixture(): void
    {
        $repo = Fixtures::agencyRepo();
        self::assertSame('Agency One', $repo->client(131)['name'] ?? null);
        self::assertNull($repo->client(1));
        self::assertCount(6, $repo->services(131));
        self::assertCount(5, $repo->addons(131));
        self::assertSame([], $repo->services(1));
        self::assertSame(131, $repo->invoice(9001)['clientId'] ?? null);
        self::assertCount(8, $repo->invoiceItems(9001));
        self::assertSame(['ZAR', 'EUR'], $repo->currencyCodes());
        self::assertSame([1 => 'Legacy', 23 => 'Premium Shared'], $repo->lookupNames('group', [1, 23, 999]));
        self::assertSame([12 => 'Advanced Email Security'], $repo->lookupNames('addon', [12, 1000]));
        self::assertSame([113 => 'sPremium-1'], $repo->lookupNames('product', [113]));
    }

    public function testServicesAreReindexedAndDoNotLeakClientId(): void
    {
        $repo = Fixtures::agencyRepo();
        $services = $repo->services(131);
        self::assertSame(range(0, 5), array_keys($services));
        self::assertArrayNotHasKey('clientId', $services[0]);
        self::assertSame(1001, $services[0]['id']);
        self::assertSame([51, 52, 53, 54, 55], array_column($repo->addons(131), 'id'));
        self::assertArrayNotHasKey('clientId', $repo->addons(131)[0]);

        $repo->services[2001]['status'] = 'Suspended';
        self::assertSame('Suspended', $repo->services(404)[0]['status']);
    }

    public function testAddInvoiceItemAppendsWithNextId(): void
    {
        $repo = Fixtures::agencyRepo();
        $id = $repo->addInvoiceItem(9001, 131, 'AgencyDiscount', 131, 'Agency discount 5% on 4 qualifying items', '-34.00', 0, '2026-10-25', 'banktransfer');
        self::assertSame(12, $id);
        self::assertSame('banktransfer', $repo->itemPaymentMethods[12]);
        self::assertSame('', $repo->invoice(9002)['paymentMethod'] ?? null, 'paymentMethod defaults to empty');
        $items = $repo->invoiceItems(9001);
        self::assertSame('AgencyDiscount', end($items)['type']);
        self::assertSame('-34.00', end($items)['amount']);
    }

    public function testAgencyUpsertAndApprovedList(): void
    {
        $repo = new ArrayRepository();
        self::assertNull($repo->agency(5));
        $row = ['clientId' => 5, 'approved' => true, 'note' => '', 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-12 10:00:00', 'currency' => 'ZAR', 'spend' => '0.00', 'tierIndex' => null, 'tierPercent' => null, 'countedServices' => 0, 'countedAddons' => 0, 'calculatedAt' => null, 'updatedAt' => '2026-10-12 10:00:00'];
        $repo->saveAgency($row);
        $repo->saveAgency(['clientId' => 4, 'approved' => false] + $row);
        $repo->saveAgency(['spend' => '12.00'] + $row);
        self::assertSame('12.00', $repo->agency(5)['spend'] ?? null);
        self::assertSame([5], array_column($repo->approvedAgencies(), 'clientId'));

        $saved = $repo->agency(5);
        self::assertNotNull($saved);
        self::assertArrayHasKey('effectiveFrom', $saved);
        self::assertNull($saved['effectiveFrom']);
        $repo->saveAgency(['clientId' => 6, 'effectiveFrom' => '2027-01-01'] + $row);
        self::assertSame('2027-01-01', $repo->agency(6)['effectiveFrom'] ?? null);
        self::assertSame([5, 6], array_column($repo->approvedAgencies(), 'clientId'));
    }

    public function testSaveAgencyFillsBlankDefaultsForMissingKeys(): void
    {
        $repo = new ArrayRepository();
        $repo->saveAgency(['clientId' => 7]);
        self::assertSame([
            'clientId' => 7,
            'approved' => false,
            'note' => '',
            'approvedBy' => '',
            'approvedAt' => null,
            'effectiveFrom' => null,
            'currency' => '',
            'spend' => '0.00',
            'tierIndex' => null,
            'tierPercent' => null,
            'countedServices' => 0,
            'countedAddons' => 0,
            'calculatedAt' => null,
            'updatedAt' => '',
        ], $repo->agency(7));
    }

    public function testLogIsNewestFirstAndFilterable(): void
    {
        $repo = new ArrayRepository();
        $repo->log(['clientId' => 1, 'invoiceId' => null, 'event' => 'approved', 'detail' => [], 'actor' => 'a', 'createdAt' => '2026-10-12 10:00:00']);
        $repo->log(['clientId' => 2, 'invoiceId' => 9, 'event' => 'applied', 'detail' => ['amount' => '1.00'], 'actor' => 'a', 'createdAt' => '2026-10-12 10:00:01']);
        $repo->log(['clientId' => 1, 'invoiceId' => null, 'event' => 'revoked', 'detail' => [], 'actor' => 'a', 'createdAt' => '2026-10-12 10:00:02']);
        self::assertSame(['revoked', 'applied', 'approved'], array_column($repo->logEntries(null, 10), 'event'));
        self::assertSame(['revoked', 'approved'], array_column($repo->logEntries(1, 10), 'event'));
        self::assertSame(['applied'], array_column($repo->logEntries(null, 1, 1), 'event'));
        self::assertSame(['amount' => '1.00'], $repo->logEntries(2, 1)[0]['detail']);
    }

    public function testInvoiceRecordUpsert(): void
    {
        $repo = new ArrayRepository();
        self::assertNull($repo->invoiceRecord(9));
        $row = ['invoiceId' => 9, 'clientId' => 1, 'itemId' => null, 'tierIndex' => 1, 'percent' => '5.00', 'base' => '100.00', 'amount' => '5.00', 'dryRun' => true, 'createdAt' => '2026-10-12 10:00:00'];
        $repo->saveInvoiceRecord($row);
        $repo->saveInvoiceRecord(['dryRun' => false, 'itemId' => 44] + $row);
        self::assertSame(44, $repo->invoiceRecord(9)['itemId'] ?? null);
        self::assertCount(1, $repo->invoiceRecords);
    }

    public function testClaimInvoiceIsFirstWriterWins(): void
    {
        $repo = new ArrayRepository();
        self::assertTrue($repo->claimInvoice(9, 1, '2026-10-12 10:00:00'));
        self::assertFalse($repo->claimInvoice(9, 1, '2026-10-12 10:00:01'));
        $record = $repo->invoiceRecord(9);
        self::assertNotNull($record);
        self::assertNull($record['itemId']);
        self::assertFalse($record['dryRun'] ?? true);
        self::assertSame('2026-10-12 10:00:00', $record['createdAt'] ?? null);
    }

    public function testCatalogueListsGroupsProductsAddonsAndCurrencies(): void
    {
        $repo = Fixtures::agencyRepo();
        $groups = $repo->productGroups();
        self::assertSame([1, 3, 6, 12, 15, 23, 33], array_column($groups, 'id'));
        self::assertSame(['id' => 23, 'name' => 'Premium Shared', 'hidden' => false, 'activeServices' => 5], $groups[5]);
        self::assertSame(['id' => 12, 'name' => 'Software', 'hidden' => true, 'activeServices' => 0], $groups[3]);
        self::assertSame(1, $groups[0]['activeServices'], 'the terminated legacy service is not active');

        $products = $repo->products();
        self::assertSame([109, 108, 45, 120, 113, 161], array_column($products, 'id'));
        self::assertSame(['id' => 113, 'groupId' => 23, 'name' => 'sPremium-1'], $products[4]);

        self::assertSame([['id' => 12, 'name' => 'Advanced Email Security'], ['id' => 15, 'name' => 'Edge Security (WAF, CDN & DDoS)'], ['id' => 99, 'name' => 'Other addon']], $repo->addonCatalog());

        self::assertSame([
            ['id' => 1, 'code' => 'ZAR', 'rate' => '1.00000', 'isDefault' => true],
            ['id' => 3, 'code' => 'EUR', 'rate' => '0.05389', 'isDefault' => false],
        ], $repo->currencies());
        self::assertSame(['ZAR', 'EUR'], $repo->currencyCodes());
    }

    public function testShortFormCurrenciesAndGroupsTakeDefaults(): void
    {
        $repo = new ArrayRepository(['currencies' => [5 => 'USD', 2 => 'GBP'], 'groups' => [4 => 'Hosting']]);
        self::assertSame([
            ['id' => 2, 'code' => 'GBP', 'rate' => '1.00000', 'isDefault' => true],
            ['id' => 5, 'code' => 'USD', 'rate' => '1.00000', 'isDefault' => false],
        ], $repo->currencies());
        self::assertSame([['id' => 4, 'name' => 'Hosting', 'hidden' => false, 'activeServices' => 0]], $repo->productGroups());
    }

    public function testSettingsAreUpsertedPerKey(): void
    {
        $repo = new ArrayRepository(['settings' => ['tiers' => '{}', 'dry_run' => 'on']]);
        $repo->saveSettings(['dry_run' => '', 'products' => '1,2']);
        self::assertSame(['tiers' => '{}', 'dry_run' => '', 'products' => '1,2'], $repo->settings());
    }

    public function testInvoicesCheckedCountsDistinctInvoices(): void
    {
        $repo = new ArrayRepository();
        self::assertSame(0, $repo->invoicesChecked());
        $event = ['clientId' => 1, 'detail' => [], 'actor' => 'system', 'createdAt' => '2026-10-12 10:00:00'];
        $repo->log($event + ['invoiceId' => 9, 'event' => 'dry_run']);
        $repo->log($event + ['invoiceId' => 9, 'event' => 'already_processed']);
        $repo->log($event + ['invoiceId' => 10, 'event' => 'applied']);
        $repo->log($event + ['invoiceId' => null, 'event' => 'tier_changed']);
        self::assertSame(2, $repo->invoicesChecked());
    }

    public function testAdminRoleId(): void
    {
        $repo = Fixtures::agencyRepo();
        self::assertSame(1, $repo->adminRoleId(1));
        self::assertSame(3, $repo->adminRoleId(2));
        self::assertNull($repo->adminRoleId(9));
    }
}
