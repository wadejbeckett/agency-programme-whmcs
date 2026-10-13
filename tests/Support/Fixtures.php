<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Support;

use AgencyDiscount\ArrayRepository;

/**
 * Shared test data. The numbers are written out literally so a reviewer can
 * check every expected total against them by hand.
 */
final class Fixtures
{
    public static function agencyRepo(): ArrayRepository
    {
        return new ArrayRepository(self::data());
    }

    /**
     * The constructor data behind agencyRepo(), for tests that subclass ArrayRepository.
     *
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [
            'currencies' => [1 => ['code' => 'ZAR', 'rate' => '1.00000', 'isDefault' => true], 3 => ['code' => 'EUR', 'rate' => '0.05389', 'isDefault' => false]],
            'groups' => [1 => 'Legacy', 3 => 'Reseller Hosting', 6 => 'Cloud', 12 => ['name' => 'Software', 'hidden' => true], 15 => 'Domains', 23 => 'Premium Shared', 33 => 'Security'],
            'products' => [
                109 => ['gid' => 1, 'name' => 'sLegacy-1'],
                113 => ['gid' => 23, 'name' => 'sPremium-1'],
                108 => ['gid' => 3, 'name' => 'Reseller 1'],
                45 => ['gid' => 6, 'name' => 'Cloud 1'],
                120 => ['gid' => 15, 'name' => 'DNS Only'],
                161 => ['gid' => 33, 'name' => 'Email Security'],
            ],
            'addonCatalog' => [12 => 'Advanced Email Security', 15 => 'Edge Security (WAF, CDN & DDoS)', 99 => 'Other addon'],
            'clients' => [
                131 => ['id' => 131, 'name' => 'Agency One', 'currency' => 'ZAR', 'currencyId' => 1],
                404 => ['id' => 404, 'name' => 'Agency Two', 'currency' => 'ZAR', 'currencyId' => 1],
                500 => ['id' => 500, 'name' => 'Euro Agency', 'currency' => 'EUR', 'currencyId' => 3],
                600 => ['id' => 600, 'name' => 'Retail Client', 'currency' => 'ZAR', 'currencyId' => 1],
            ],
            'services' => [
                // client 131
                1001 => ['clientId' => 131, 'id' => 1001, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'a.example', 'amount' => '170.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0],
                1002 => ['clientId' => 131, 'id' => 1002, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'b.example', 'amount' => '340.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0],
                1003 => ['clientId' => 131, 'id' => 1003, 'productId' => 109, 'groupId' => 1, 'productName' => 'sLegacy-1', 'domain' => 'c.example', 'amount' => '1020.00', 'billingCycle' => 'Annually', 'status' => 'Active', 'nextInvoiceDate' => '2026-11-03', 'promoId' => 0],
                1004 => ['clientId' => 131, 'id' => 1004, 'productId' => 109, 'groupId' => 1, 'productName' => 'sLegacy-1', 'domain' => 'd.example', 'amount' => '85.00', 'billingCycle' => 'Monthly', 'status' => 'Terminated', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0],
                1005 => ['clientId' => 131, 'id' => 1005, 'productId' => 120, 'groupId' => 15, 'productName' => 'DNS Only', 'domain' => 'e.example', 'amount' => '68.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0],
                1006 => ['clientId' => 131, 'id' => 1006, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'f.example', 'amount' => '170.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 8],
                // client 404
                2001 => ['clientId' => 404, 'id' => 2001, 'productId' => 108, 'groupId' => 3, 'productName' => 'Reseller 1', 'domain' => 'r.example', 'amount' => '6000.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-20', 'promoId' => 0],
                // client 500
                3001 => ['clientId' => 500, 'id' => 3001, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'eu.example', 'amount' => '300.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-20', 'promoId' => 0],
                // client 600
                4001 => ['clientId' => 600, 'id' => 4001, 'productId' => 113, 'groupId' => 23, 'productName' => 'sPremium-1', 'domain' => 'x.example', 'amount' => '170.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25', 'promoId' => 0],
            ],
            'addons' => [
                // client 131
                51 => ['clientId' => 131, 'id' => 51, 'hostingId' => 1001, 'addonId' => 12, 'addonName' => 'Advanced Email Security', 'domain' => 'a.example', 'recurring' => '85.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25'],
                52 => ['clientId' => 131, 'id' => 52, 'hostingId' => 1002, 'addonId' => 15, 'addonName' => 'Edge Security (WAF, CDN & DDoS)', 'domain' => 'b.example', 'recurring' => '85.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25'],
                53 => ['clientId' => 131, 'id' => 53, 'hostingId' => 1003, 'addonId' => 12, 'addonName' => 'Advanced Email Security', 'domain' => 'c.example', 'recurring' => '935.00', 'billingCycle' => 'Annually', 'status' => 'Active', 'nextInvoiceDate' => '2026-11-03'],
                54 => ['clientId' => 131, 'id' => 54, 'hostingId' => 1001, 'addonId' => 99, 'addonName' => 'Other addon', 'domain' => 'a.example', 'recurring' => '50.00', 'billingCycle' => 'Monthly', 'status' => 'Active', 'nextInvoiceDate' => '2026-10-25'],
                55 => ['clientId' => 131, 'id' => 55, 'hostingId' => 1004, 'addonId' => 12, 'addonName' => 'Advanced Email Security', 'domain' => 'd.example', 'recurring' => '85.00', 'billingCycle' => 'Monthly', 'status' => 'Terminated', 'nextInvoiceDate' => '2026-10-25'],
            ],
            'invoices' => [
                9001 => ['id' => 9001, 'clientId' => 131, 'status' => 'Unpaid', 'dueDate' => '2026-10-25', 'paymentMethod' => 'banktransfer'],
                9002 => ['id' => 9002, 'clientId' => 131, 'status' => 'Unpaid', 'dueDate' => '2026-10-26'],
                9003 => ['id' => 9003, 'clientId' => 500, 'status' => 'Unpaid', 'dueDate' => '2026-10-20'],
                9004 => ['id' => 9004, 'clientId' => 600, 'status' => 'Unpaid', 'dueDate' => '2026-10-25'],
            ],
            'invoiceItems' => [
                9001 => [
                    ['id' => 1, 'type' => 'Hosting', 'relId' => 1001, 'description' => 'sPremium-1 - a.example', 'amount' => '170.00', 'taxed' => 0],
                    ['id' => 2, 'type' => 'Hosting', 'relId' => 1002, 'description' => 'sPremium-1 - b.example', 'amount' => '340.00', 'taxed' => 0],
                    ['id' => 3, 'type' => 'Hosting', 'relId' => 1005, 'description' => 'DNS Only - e.example', 'amount' => '68.00', 'taxed' => 0],
                    ['id' => 4, 'type' => 'Addon', 'relId' => 51, 'description' => 'Addon (a.example) - Advanced Email Security', 'amount' => '85.00', 'taxed' => 0],
                    ['id' => 5, 'type' => 'Addon', 'relId' => 54, 'description' => 'Addon (a.example) - Other addon', 'amount' => '50.00', 'taxed' => 0],
                    ['id' => 6, 'type' => 'Hosting', 'relId' => 1006, 'description' => 'sPremium-1 - f.example', 'amount' => '170.00', 'taxed' => 0],
                    ['id' => 7, 'type' => 'PromoHosting', 'relId' => 1006, 'description' => 'Promo', 'amount' => '-17.00', 'taxed' => 0],
                    ['id' => 8, 'type' => 'Item', 'relId' => 0, 'description' => 'Manual work', 'amount' => '1200.00', 'taxed' => 0],
                ],
                9002 => [
                    ['id' => 9, 'type' => 'Domain', 'relId' => 77, 'description' => 'Domain renewal', 'amount' => '199.00', 'taxed' => 0],
                ],
                9003 => [
                    ['id' => 10, 'type' => 'Hosting', 'relId' => 3001, 'description' => 'sPremium-1 - eu.example', 'amount' => '300.00', 'taxed' => 0],
                ],
                9004 => [
                    ['id' => 11, 'type' => 'Hosting', 'relId' => 4001, 'description' => 'sPremium-1 - x.example', 'amount' => '170.00', 'taxed' => 0],
                ],
            ],
        ];
    }

    /** @return array<string,string> */
    public static function settings(): array
    {
        return [
            'tiers' => '{"ZAR":[{"above":1000,"percent":5},{"above":2000,"percent":10},{"above":3000,"percent":15}]}',
            'product_groups' => '1,23,3,6',
            'products' => '',
            'addons' => '12,15',
            'statuses' => 'Active',
            'dry_run' => '',
            'description' => 'Agency discount {percent}% on {count} qualifying items',
            'keep_data' => '',
            'activity_log' => 'on',
            'stack_with_promotions' => '',
        ];
    }
}
