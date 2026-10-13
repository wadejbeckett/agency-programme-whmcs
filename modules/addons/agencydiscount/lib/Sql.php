<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * Raw statements against WHMCS and module tables. Kept as constants so the
 * integration test can run each one against a real database and compare the
 * column names with expectedColumns().
 */
final class Sql
{
    public const CLIENT = 'SELECT c.id, c.firstname, c.lastname, c.companyname, c.currency AS currency_id, cu.code AS currency FROM tblclients c LEFT JOIN tblcurrencies cu ON cu.id = c.currency WHERE c.id = ?';
    public const SERVICES = 'SELECT h.id, h.packageid AS product_id, p.gid AS group_id, p.name AS product_name, h.domain, h.amount, h.billingcycle, h.domainstatus AS status, h.nextinvoicedate, h.promoid FROM tblhosting h JOIN tblproducts p ON p.id = h.packageid WHERE h.userid = ? ORDER BY h.id';
    public const ADDONS = 'SELECT a.id, a.hostingid, a.addonid, ad.name AS addon_name, COALESCE(h.domain, \'\') AS domain, a.recurring, a.billingcycle, a.status, a.nextinvoicedate FROM tblhostingaddons a JOIN tbladdons ad ON ad.id = a.addonid LEFT JOIN tblhosting h ON h.id = a.hostingid WHERE a.userid = ? OR h.userid = ? ORDER BY a.id';
    public const INVOICE = 'SELECT id, userid AS client_id, status, duedate, paymentmethod FROM tblinvoices WHERE id = ?';
    public const INVOICE_ITEMS = 'SELECT id, type, relid, description, amount, taxed FROM tblinvoiceitems WHERE invoiceid = ? ORDER BY id';
    public const GROUP_NAMES = 'SELECT id, name FROM tblproductgroups WHERE id IN (%s)';
    public const PRODUCT_NAMES = 'SELECT id, name FROM tblproducts WHERE id IN (%s)';
    public const ADDON_NAMES = 'SELECT id, name FROM tbladdons WHERE id IN (%s)';
    public const CURRENCIES = 'SELECT id, code, rate, `default` AS is_default FROM tblcurrencies ORDER BY id';
    public const PRODUCT_GROUPS = 'SELECT g.id, g.name, g.hidden, (SELECT COUNT(*) FROM tblhosting h JOIN tblproducts p ON p.id = h.packageid WHERE p.gid = g.id AND h.domainstatus = \'Active\') AS active_services FROM tblproductgroups g ORDER BY g.`order`, g.id';
    public const PRODUCTS = 'SELECT id, gid AS group_id, name FROM tblproducts ORDER BY gid, `order`, id';
    public const ADDON_CATALOG = 'SELECT id, name FROM tbladdons ORDER BY name, id';
    public const SETTINGS = 'SELECT setting, value FROM tbladdonmodules WHERE module = ?';
    public const ADMIN_ROLE = 'SELECT roleid FROM tbladmins WHERE id = ?';
    public const APPLIED_DISCOUNT_TOTAL = 'SELECT COALESCE(SUM(amount), 0) AS total FROM mod_agencydiscount_invoices WHERE client_id = ? AND dry_run = 0 AND created_at >= ?';
    public const CLAIM_INVOICE = 'INSERT IGNORE INTO mod_agencydiscount_invoices (invoice_id, client_id, item_id, tier_index, percent, base, amount, dry_run, created_at) VALUES (?, ?, NULL, 0, 0.00, 0.00, 0.00, 0, ?)';

    /**
     * @param list<int> $ids
     * @return string comma-separated "?" placeholders, one per id; "NULL" when there are none (IN (NULL) matches nothing)
     */
    public static function placeholders(array $ids): string
    {
        return $ids === [] ? 'NULL' : implode(',', array_fill(0, count($ids), '?'));
    }

    /** Statements on the module's own tables, which only exist once the module is activated. */
    public static function onModuleTables(string $statement): bool
    {
        return str_contains($statement, 'mod_agencydiscount_');
    }

    /**
     * Statements that return no rows map to an empty list.
     *
     * @return array<string, list<string>> constant name => expected column names
     */
    public static function expectedColumns(): array
    {
        return [
            'CLIENT' => ['id', 'firstname', 'lastname', 'companyname', 'currency_id', 'currency'],
            'SERVICES' => ['id', 'product_id', 'group_id', 'product_name', 'domain', 'amount', 'billingcycle', 'status', 'nextinvoicedate', 'promoid'],
            'ADDONS' => ['id', 'hostingid', 'addonid', 'addon_name', 'domain', 'recurring', 'billingcycle', 'status', 'nextinvoicedate'],
            'INVOICE' => ['id', 'client_id', 'status', 'duedate', 'paymentmethod'],
            'INVOICE_ITEMS' => ['id', 'type', 'relid', 'description', 'amount', 'taxed'],
            'GROUP_NAMES' => ['id', 'name'],
            'PRODUCT_NAMES' => ['id', 'name'],
            'ADDON_NAMES' => ['id', 'name'],
            'CURRENCIES' => ['id', 'code', 'rate', 'is_default'],
            'PRODUCT_GROUPS' => ['id', 'name', 'hidden', 'active_services'],
            'PRODUCTS' => ['id', 'group_id', 'name'],
            'ADDON_CATALOG' => ['id', 'name'],
            'SETTINGS' => ['setting', 'value'],
            'ADMIN_ROLE' => ['roleid'],
            'APPLIED_DISCOUNT_TOTAL' => ['total'],
            'CLAIM_INVOICE' => [],
        ];
    }
}
