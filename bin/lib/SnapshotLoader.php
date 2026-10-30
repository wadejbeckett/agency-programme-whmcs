<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * Builds an ArrayRepository from a directory of JSON table exports (tools/snapshot.sh),
 * so the CLI can rehearse the module against a copy of a WHMCS database without
 * touching it. Field mapping mirrors CapsuleRepository.
 */
final class SnapshotLoader
{
    public const TABLES = ['tblcurrencies', 'tblproductgroups', 'tblproducts', 'tbladdons', 'tblclients', 'tblhosting', 'tblhostingaddons', 'tblinvoices', 'tblinvoiceitems'];

    /**
     * Reads <dir>/<table>.json for each of TABLES (a JSON array of row objects with
     * WHMCS column names). Missing files are treated as empty tables.
     *
     * @throws \RuntimeException when the directory is missing or a file is not a JSON array of objects
     */
    public static function load(string $dir): ArrayRepository
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException('snapshot: ' . $dir . ' is not a directory');
        }
        $t = [];
        foreach (self::TABLES as $table) {
            $t[$table] = self::rows($dir, $table);
        }

        $currencies = [];
        foreach ($t['tblcurrencies'] as $row) {
            $currencies[self::int($row, 'id')] = self::str($row, 'code');
        }
        ksort($currencies);

        $groups = [];
        foreach ($t['tblproductgroups'] as $row) {
            $groups[self::int($row, 'id')] = self::str($row, 'name');
        }

        $products = [];
        foreach ($t['tblproducts'] as $row) {
            $products[self::int($row, 'id')] = ['gid' => self::int($row, 'gid'), 'name' => self::str($row, 'name')];
        }

        $addonCatalog = [];
        foreach ($t['tbladdons'] as $row) {
            $addonCatalog[self::int($row, 'id')] = self::str($row, 'name');
        }

        $clients = [];
        foreach ($t['tblclients'] as $row) {
            $id = self::int($row, 'id');
            $currencyId = self::int($row, 'currency');
            $company = trim(self::str($row, 'companyname'));
            $clients[$id] = [
                'id' => $id,
                'name' => $company !== '' ? $company : trim(self::str($row, 'firstname') . ' ' . self::str($row, 'lastname')),
                'currency' => $currencies[$currencyId] ?? '',
                'currencyId' => $currencyId,
            ];
        }

        $services = [];
        foreach ($t['tblhosting'] as $row) {
            $id = self::int($row, 'id');
            $productId = self::int($row, 'packageid');
            $services[$id] = [
                'clientId' => self::int($row, 'userid'),
                'id' => $id,
                'productId' => $productId,
                'groupId' => $products[$productId]['gid'] ?? 0,
                'productName' => $products[$productId]['name'] ?? 'product #' . $productId,
                'domain' => self::str($row, 'domain'),
                'amount' => self::money($row, 'amount'),
                'billingCycle' => self::str($row, 'billingcycle'),
                'status' => self::str($row, 'domainstatus'),
                'nextInvoiceDate' => self::date($row, 'nextinvoicedate'),
                'promoId' => self::int($row, 'promoid'),
            ];
        }
        ksort($services);

        $addons = [];
        foreach ($t['tblhostingaddons'] as $row) {
            $id = self::int($row, 'id');
            $hostingId = self::int($row, 'hostingid');
            $addonId = self::int($row, 'addonid');
            $clientId = self::int($row, 'userid');
            $addons[$id] = [
                'clientId' => $clientId !== 0 ? $clientId : ($services[$hostingId]['clientId'] ?? 0),
                'id' => $id,
                'hostingId' => $hostingId,
                'addonId' => $addonId,
                'addonName' => $addonCatalog[$addonId] ?? 'addon #' . $addonId,
                'domain' => $services[$hostingId]['domain'] ?? '',
                'recurring' => self::money($row, 'recurring'),
                'billingCycle' => self::str($row, 'billingcycle'),
                'status' => self::str($row, 'status'),
                'nextInvoiceDate' => self::date($row, 'nextinvoicedate'),
            ];
        }
        ksort($addons);

        $invoices = [];
        foreach ($t['tblinvoices'] as $row) {
            $id = self::int($row, 'id');
            $invoices[$id] = [
                'id' => $id,
                'clientId' => self::int($row, 'userid'),
                'status' => self::str($row, 'status'),
                'dueDate' => self::date($row, 'duedate'),
                'paymentMethod' => self::str($row, 'paymentmethod'),
            ];
        }

        $byInvoice = [];
        foreach ($t['tblinvoiceitems'] as $row) {
            $byInvoice[self::int($row, 'invoiceid')][self::int($row, 'id')] = [
                'id' => self::int($row, 'id'),
                'type' => self::str($row, 'type'),
                'relId' => self::int($row, 'relid'),
                'description' => self::str($row, 'description'),
                'amount' => self::money($row, 'amount'),
                'taxed' => self::int($row, 'taxed'),
            ];
        }
        $invoiceItems = [];
        foreach ($byInvoice as $invoiceId => $items) {
            ksort($items);
            $invoiceItems[$invoiceId] = array_values($items);
        }

        return new ArrayRepository([
            'clients' => $clients,
            'services' => $services,
            'addons' => $addons,
            'invoices' => $invoices,
            'invoiceItems' => $invoiceItems,
            'products' => $products,
            'groups' => $groups,
            'addonCatalog' => $addonCatalog,
            'currencies' => $currencies,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $dir, string $table): array
    {
        $file = $table . '.json';
        $path = rtrim($dir, '/') . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        $data = $json === false || !str_starts_with(ltrim($json), '[') ? null : json_decode($json, true);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('snapshot: ' . $file . ' is not a JSON array');
        }
        $rows = [];
        foreach ($data as $i => $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                throw new \RuntimeException('snapshot: ' . $file . ' row ' . ($i + 1) . ' is not an object');
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Two-place decimal string. tools/snapshot.sh exports amounts as strings; a JSON
     * number is accepted too and rounded to two places.
     *
     * @param array<string, mixed> $row
     */
    private static function money(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_float($value)) {
            $value = sprintf('%.2F', $value);
        }
        return bcadd(is_numeric($value) ? (string) $value : '0', '0', 2);
    }

    /**
     * 'Y-m-d' as stored; null, '' and zero dates become null.
     *
     * @param array<string, mixed> $row
     */
    private static function date(array $row, string $key): ?string
    {
        $date = self::str($row, $key);
        return $date === '' || str_starts_with($date, '0000-00-00') ? null : $date;
    }
}
