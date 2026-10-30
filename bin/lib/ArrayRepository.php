<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * In-memory Repository for tests. State lives in public arrays so tests can
 * set up and inspect it directly. Not final, so a test can override one method
 * to simulate a race or a failure.
 *
 * @phpstan-import-type AgencyRow from Repository
 * @phpstan-import-type InvoiceRecordRow from Repository
 * @phpstan-import-type LogEvent from Repository
 */
class ArrayRepository implements Repository
{
    /** @var array<int, array{id:int, name:string, currency:string, currencyId:int}> */
    public array $clients = [];
    /** @var array<int, array{clientId:int, id:int, productId:int, groupId:int, productName:string, domain:string, amount:string, billingCycle:string, status:string, nextInvoiceDate:?string, promoId:int}> */
    public array $services = [];
    /** @var array<int, array{clientId:int, id:int, hostingId:int, addonId:int, addonName:string, domain:string, recurring:string, billingCycle:string, status:string, nextInvoiceDate:?string}> */
    public array $addons = [];
    /** @var array<int, array{id:int, clientId:int, status:string, dueDate:?string, paymentMethod?:string}> paymentMethod defaults to '' */
    public array $invoices = [];
    /** @var array<int, list<array{id:int, type:string, relId:int, description:string, amount:string, taxed:int}>> */
    public array $invoiceItems = [];
    /** @var array<int, AgencyRow> */
    public array $agencies = [];
    /** @var list<array{id:int, clientId:?int, invoiceId:?int, event:string, detail:array<string,mixed>, actor:string, createdAt:string}> */
    public array $logs = [];
    /** @var array<int, array<int, InvoiceRecordRow>> invoice id => line => record */
    public array $invoiceRecords = [];
    /** @var list<string> */
    public array $executed = [];
    /** @var list<string> indexes that exist before any statement runs, as "table.index" */
    public array $indexes = [];
    /** @var array<int, string> item id => payment method, for lines added through addInvoiceItem */
    public array $itemPaymentMethods = [];
    /** @var array<string, string> setting => value, as written by saveSettings */
    public array $settings = [];
    /** @var array<int, int> admin id => role id */
    public array $admins = [];

    /** @var array<int, array{gid:int, name:string}> */
    private array $products = [];
    /** @var array<int, array{name:string, hidden:bool}> */
    private array $groups = [];
    /** @var array<int, string> */
    private array $addonCatalog = [];
    /** @var array<int, array{code:string, rate:string, isDefault:bool}> */
    private array $currencies = [];

    /**
     * @param array{
     *     clients?: array<int, array{id:int, name:string, currency:string, currencyId:int}>,
     *     services?: array<int, array{clientId:int, id:int, productId:int, groupId:int, productName:string, domain:string, amount:string, billingCycle:string, status:string, nextInvoiceDate:?string, promoId:int}>,
     *     addons?: array<int, array{clientId:int, id:int, hostingId:int, addonId:int, addonName:string, domain:string, recurring:string, billingCycle:string, status:string, nextInvoiceDate:?string}>,
     *     invoices?: array<int, array{id:int, clientId:int, status:string, dueDate:?string, paymentMethod?:string}>,
     *     invoiceItems?: array<int, list<array{id:int, type:string, relId:int, description:string, amount:string, taxed:int}>>,
     *     products?: array<int, array{gid:int, name:string}>,
     *     groups?: array<int, string|array{name:string, hidden?:bool}>,
     *     addonCatalog?: array<int, string>,
     *     currencies?: array<int, string|array{code:string, rate?:string, isDefault?:bool}>,
     *     settings?: array<string, string>,
     *     admins?: array<int, int>
     * } $data a group given as a string is visible; a currency given as a string has rate 1.00000, and
     *         when no currency is marked default the one with the lowest id is
     */
    public function __construct(array $data = [])
    {
        $this->clients = $data['clients'] ?? [];
        $this->services = $data['services'] ?? [];
        $this->addons = $data['addons'] ?? [];
        $this->invoices = $data['invoices'] ?? [];
        $this->invoiceItems = $data['invoiceItems'] ?? [];
        $this->products = $data['products'] ?? [];
        $this->addonCatalog = $data['addonCatalog'] ?? [];
        $this->settings = $data['settings'] ?? [];
        $this->admins = $data['admins'] ?? [];
        foreach ($data['groups'] ?? [] as $id => $group) {
            $this->groups[$id] = is_string($group) ? ['name' => $group, 'hidden' => false] : ['name' => $group['name'], 'hidden' => $group['hidden'] ?? false];
        }
        $currencies = $data['currencies'] ?? [];
        ksort($currencies);
        $hasDefault = false;
        foreach ($currencies as $currency) {
            $hasDefault = $hasDefault || (is_array($currency) && ($currency['isDefault'] ?? false));
        }
        foreach ($currencies as $id => $currency) {
            $this->currencies[$id] = is_string($currency)
                ? ['code' => $currency, 'rate' => '1.00000', 'isDefault' => !$hasDefault && $this->currencies === []]
                : ['code' => $currency['code'], 'rate' => $currency['rate'] ?? '1.00000', 'isDefault' => $currency['isDefault'] ?? (!$hasDefault && $this->currencies === [])];
        }
    }

    public function client(int $clientId): ?array
    {
        return $this->clients[$clientId] ?? null;
    }

    public function services(int $clientId): array
    {
        $rows = [];
        foreach ($this->services as $row) {
            if ($row['clientId'] === $clientId) {
                unset($row['clientId']);
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function addons(int $clientId): array
    {
        $rows = [];
        foreach ($this->addons as $row) {
            if ($row['clientId'] === $clientId) {
                unset($row['clientId']);
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function invoice(int $invoiceId): ?array
    {
        $invoice = $this->invoices[$invoiceId] ?? null;
        return $invoice === null ? null : $invoice + ['paymentMethod' => ''];
    }

    public function invoiceItems(int $invoiceId): array
    {
        return array_values($this->invoiceItems[$invoiceId] ?? []);
    }

    public function addInvoiceItem(int $invoiceId, int $clientId, string $type, int $relId, string $description, string $amount, int $taxed, ?string $dueDate, string $paymentMethod): int
    {
        $maxId = 0;
        foreach ($this->invoiceItems as $items) {
            foreach ($items as $item) {
                $maxId = max($maxId, $item['id']);
            }
        }
        $id = $maxId + 1;
        $this->invoiceItems[$invoiceId][] = [
            'id' => $id,
            'type' => $type,
            'relId' => $relId,
            'description' => $description,
            'amount' => $amount,
            'taxed' => $taxed,
        ];
        $this->itemPaymentMethods[$id] = $paymentMethod;
        return $id;
    }

    public function agency(int $clientId): ?array
    {
        return $this->agencies[$clientId] ?? null;
    }

    public function approvedAgencies(): array
    {
        $rows = array_filter($this->agencies, static fn (array $row): bool => $row['approved']);
        ksort($rows);
        return array_values($rows);
    }

    /**
     * Missing keys take the blank default (approved false, empty strings,
     * null dates and tier, zero counts, spend 0.00).
     *
     * @param array<string,mixed> $row
     */
    public function saveAgency(array $row): void
    {
        /** @var AgencyRow $normalised */
        $normalised = [
            'clientId' => $row['clientId'],
            'approved' => $row['approved'] ?? false,
            'note' => $row['note'] ?? '',
            'approvedBy' => $row['approvedBy'] ?? '',
            'approvedAt' => $row['approvedAt'] ?? null,
            'effectiveFrom' => $row['effectiveFrom'] ?? null,
            'currency' => $row['currency'] ?? '',
            'spend' => $row['spend'] ?? '0.00',
            'tierIndex' => $row['tierIndex'] ?? null,
            'tierPercent' => $row['tierPercent'] ?? null,
            'countedServices' => $row['countedServices'] ?? 0,
            'countedAddons' => $row['countedAddons'] ?? 0,
            'calculatedAt' => $row['calculatedAt'] ?? null,
            'updatedAt' => $row['updatedAt'] ?? '',
        ];
        $this->agencies[$normalised['clientId']] = $normalised;
    }

    public function saveCalculation(int $clientId, array $calculation): void
    {
        if (isset($this->agencies[$clientId])) {
            $this->agencies[$clientId] = $calculation + $this->agencies[$clientId];
        }
    }

    public function log(array $event): void
    {
        $id = $this->logs === [] ? 1 : $this->logs[array_key_last($this->logs)]['id'] + 1;
        $this->logs[] = ['id' => $id] + $event;
    }

    public function pruneLog(string $before): int
    {
        $kept = array_values(array_filter($this->logs, static fn (array $row): bool => $row['createdAt'] >= $before));
        $pruned = count($this->logs) - count($kept);
        $this->logs = $kept;
        return $pruned;
    }

    public function logEntries(?int $clientId, int $limit, int $offset = 0): array
    {
        $rows = array_filter(
            $this->logs,
            static fn (array $row): bool => $clientId === null || $row['clientId'] === $clientId,
        );
        usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        return array_slice($rows, $offset, $limit);
    }

    public function invoiceRecord(int $invoiceId): ?array
    {
        return $this->invoiceRecordLines($invoiceId)[0] ?? null;
    }

    public function invoiceRecordLines(int $invoiceId): array
    {
        $lines = $this->invoiceRecords[$invoiceId] ?? [];
        ksort($lines);
        return array_values($lines);
    }

    public function claimInvoice(int $invoiceId, int $clientId, string $createdAt): bool
    {
        if (isset($this->invoiceRecords[$invoiceId][0])) {
            return false;
        }
        $this->invoiceRecords[$invoiceId][0] = [
            'invoiceId' => $invoiceId,
            'line' => 0,
            'clientId' => $clientId,
            'itemId' => null,
            'taxed' => false,
            'tierIndex' => 0,
            'percent' => '0.00',
            'base' => '0.00',
            'amount' => '0.00',
            'dryRun' => false,
            'createdAt' => $createdAt,
        ];
        return true;
    }

    public function saveInvoiceRecord(array $row): void
    {
        $this->invoiceRecords[$row['invoiceId']][$row['line']] = $row;
    }

    public function lookupNames(string $kind, array $ids): array
    {
        $catalog = match ($kind) {
            'group' => array_map(static fn (array $g): string => $g['name'], $this->groups),
            'product' => array_map(static fn (array $p): string => $p['name'], $this->products),
            'addon' => $this->addonCatalog,
        };
        $names = [];
        foreach ($ids as $id) {
            if (isset($catalog[$id])) {
                $names[$id] = $catalog[$id];
            }
        }
        return $names;
    }

    public function currencyCodes(): array
    {
        return array_column($this->currencies(), 'code');
    }

    /** Groups come back in id order; hidden groups keep their place (callers sort them last if they want). */
    public function productGroups(): array
    {
        $active = [];
        foreach ($this->services as $service) {
            if ($service['status'] === 'Active') {
                $active[$service['groupId']] = ($active[$service['groupId']] ?? 0) + 1;
            }
        }
        $groups = $this->groups;
        ksort($groups);
        $rows = [];
        foreach ($groups as $id => $group) {
            $rows[] = ['id' => $id, 'name' => $group['name'], 'hidden' => $group['hidden'], 'activeServices' => $active[$id] ?? 0];
        }
        return $rows;
    }

    public function products(): array
    {
        $rows = [];
        foreach ($this->products as $id => $product) {
            $rows[] = ['id' => $id, 'groupId' => $product['gid'], 'name' => $product['name']];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['groupId'], $a['id']] <=> [$b['groupId'], $b['id']]);
        return $rows;
    }

    public function addonCatalog(): array
    {
        $rows = [];
        foreach ($this->addonCatalog as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
        return $rows;
    }

    public function currencies(): array
    {
        $rows = [];
        foreach ($this->currencies as $id => $currency) {
            $rows[] = ['id' => $id] + $currency;
        }
        return $rows;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function deleteSettings(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->settings[$key]);
        }
    }

    /** True for an index named in $indexes ("table.index") or added by an executed ALTER TABLE statement. */
    public function hasIndex(string $table, string $index): bool
    {
        if (in_array($table . '.' . $index, $this->indexes, true)) {
            return true;
        }
        foreach ($this->executed as $sql) {
            if (str_starts_with($sql, 'ALTER TABLE ' . $table . ' ') && preg_match('/ADD (?:INDEX|KEY|UNIQUE KEY) ' . preg_quote($index, '/') . '\b/', $sql) === 1) {
                return true;
            }
        }
        return false;
    }

    public function saveSettings(array $settings): void
    {
        foreach ($settings as $setting => $value) {
            $this->settings[$setting] = $value;
        }
    }

    public function invoicesChecked(): int
    {
        $ids = [];
        foreach ($this->logs as $entry) {
            if ($entry['invoiceId'] !== null) {
                $ids[$entry['invoiceId']] = true;
            }
        }
        return count($ids);
    }

    public function adminRoleId(int $adminId): ?int
    {
        return $this->admins[$adminId] ?? null;
    }

    public function appliedDiscountTotal(int $clientId, string $fromDate): string
    {
        $total = '0.00';
        foreach ($this->invoiceRecords as $lines) {
            foreach ($lines as $record) {
                if ($record['clientId'] === $clientId && !$record['dryRun'] && $record['createdAt'] >= $fromDate
                    && $this->lineStillOnInvoice($record['invoiceId'], $record['itemId'])) {
                    $total = bcadd($total, $record['amount'], 2);
                }
            }
        }
        return $total;
    }

    private function lineStillOnInvoice(int $invoiceId, ?int $itemId): bool
    {
        $status = $this->invoices[$invoiceId]['status'] ?? null;
        if ($itemId === null || $status === null || in_array($status, ['Cancelled', 'Refunded'], true)) {
            return false;
        }
        foreach ($this->invoiceItems[$invoiceId] ?? [] as $item) {
            if ($item['id'] === $itemId) {
                return true;
            }
        }
        return false;
    }

    /** On a throw, invoice items, invoice records and item payment methods go back to their state before $work. */
    public function transaction(callable $work): mixed
    {
        $items = $this->invoiceItems;
        $records = $this->invoiceRecords;
        $methods = $this->itemPaymentMethods;
        try {
            return $work();
        } catch (\Throwable $e) {
            $this->invoiceItems = $items;
            $this->invoiceRecords = $records;
            $this->itemPaymentMethods = $methods;
            throw $e;
        }
    }

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;
    }
}
