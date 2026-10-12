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
    /** @var array<int, InvoiceRecordRow> */
    public array $invoiceRecords = [];
    /** @var list<string> */
    public array $executed = [];
    /** @var array<int, string> item id => payment method, for lines added through addInvoiceItem */
    public array $itemPaymentMethods = [];

    /** @var array<int, array{gid:int, name:string}> */
    private array $products = [];
    /** @var array<int, string> */
    private array $groups = [];
    /** @var array<int, string> */
    private array $addonCatalog = [];
    /** @var array<int, string> */
    private array $currencies = [];

    /**
     * @param array{
     *     clients?: array<int, array{id:int, name:string, currency:string, currencyId:int}>,
     *     services?: array<int, array{clientId:int, id:int, productId:int, groupId:int, productName:string, domain:string, amount:string, billingCycle:string, status:string, nextInvoiceDate:?string, promoId:int}>,
     *     addons?: array<int, array{clientId:int, id:int, hostingId:int, addonId:int, addonName:string, domain:string, recurring:string, billingCycle:string, status:string, nextInvoiceDate:?string}>,
     *     invoices?: array<int, array{id:int, clientId:int, status:string, dueDate:?string, paymentMethod?:string}>,
     *     invoiceItems?: array<int, list<array{id:int, type:string, relId:int, description:string, amount:string, taxed:int}>>,
     *     products?: array<int, array{gid:int, name:string}>,
     *     groups?: array<int, string>,
     *     addonCatalog?: array<int, string>,
     *     currencies?: array<int, string>
     * } $data
     */
    public function __construct(array $data = [])
    {
        $this->clients = $data['clients'] ?? [];
        $this->services = $data['services'] ?? [];
        $this->addons = $data['addons'] ?? [];
        $this->invoices = $data['invoices'] ?? [];
        $this->invoiceItems = $data['invoiceItems'] ?? [];
        $this->products = $data['products'] ?? [];
        $this->groups = $data['groups'] ?? [];
        $this->addonCatalog = $data['addonCatalog'] ?? [];
        $this->currencies = $data['currencies'] ?? [];
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

    public function log(array $event): void
    {
        $id = $this->logs === [] ? 1 : $this->logs[array_key_last($this->logs)]['id'] + 1;
        $this->logs[] = ['id' => $id] + $event;
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
        return $this->invoiceRecords[$invoiceId] ?? null;
    }

    public function claimInvoice(int $invoiceId, int $clientId, string $createdAt): bool
    {
        if (isset($this->invoiceRecords[$invoiceId])) {
            return false;
        }
        $this->invoiceRecords[$invoiceId] = [
            'invoiceId' => $invoiceId,
            'clientId' => $clientId,
            'itemId' => null,
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
        $this->invoiceRecords[$row['invoiceId']] = $row;
    }

    public function lookupNames(string $kind, array $ids): array
    {
        $catalog = match ($kind) {
            'group' => $this->groups,
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
        ksort($this->currencies);
        return array_values($this->currencies);
    }

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;
    }
}
