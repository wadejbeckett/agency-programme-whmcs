<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * The data seam. Amounts are two-place decimal strings, dates are 'Y-m-d'
 * strings or null, datetimes are 'Y-m-d H:i:s'. Production binds this to the
 * WHMCS database; tests use ArrayRepository.
 *
 * @phpstan-type AgencyRow array{clientId:int, approved:bool, note:string, approvedBy:string, approvedAt:?string, effectiveFrom:?string, currency:string, spend:string, tierIndex:?int, tierPercent:?string, countedServices:int, countedAddons:int, calculatedAt:?string, updatedAt:string}
 * @phpstan-type InvoiceRecordRow array{invoiceId:int, clientId:int, itemId:?int, tierIndex:int, percent:string, base:string, amount:string, dryRun:bool, createdAt:string}
 * @phpstan-type LogEvent array{clientId:?int, invoiceId:?int, event:string, detail:array<string,mixed>, actor:string, createdAt:string}
 */
interface Repository
{
    /** @return array{id:int, name:string, currency:string, currencyId:int}|null  name = companyname if non-empty else "firstname lastname" trimmed */
    public function client(int $clientId): ?array;

    /** @return list<array{id:int, productId:int, groupId:int, productName:string, domain:string, amount:string, billingCycle:string, status:string, nextInvoiceDate:?string, promoId:int}> */
    public function services(int $clientId): array;

    /** @return list<array{id:int, hostingId:int, addonId:int, addonName:string, domain:string, recurring:string, billingCycle:string, status:string, nextInvoiceDate:?string}> */
    public function addons(int $clientId): array;

    /** @return array{id:int, clientId:int, status:string, dueDate:?string, paymentMethod:string}|null  paymentMethod is the invoice's gateway, '' when none */
    public function invoice(int $invoiceId): ?array;

    /** @return list<array{id:int, type:string, relId:int, description:string, amount:string, taxed:int}> */
    public function invoiceItems(int $invoiceId): array;

    /** @return int the new tblinvoiceitems id */
    public function addInvoiceItem(int $invoiceId, int $clientId, string $type, int $relId, string $description, string $amount, int $taxed, ?string $dueDate, string $paymentMethod): int;

    /** @return AgencyRow|null */
    public function agency(int $clientId): ?array;

    /** @return list<AgencyRow> approved rows only, ascending clientId */
    public function approvedAgencies(): array;

    /** @param AgencyRow $row  upsert by clientId */
    public function saveAgency(array $row): void;

    /** @param LogEvent $event */
    public function log(array $event): void;

    /** @return list<array{id:int, clientId:?int, invoiceId:?int, event:string, detail:array<string,mixed>, actor:string, createdAt:string}> newest first */
    public function logEntries(?int $clientId, int $limit, int $offset = 0): array;

    /** @return InvoiceRecordRow|null */
    public function invoiceRecord(int $invoiceId): ?array;

    /**
     * First-writer-wins claim on an invoice: inserts a placeholder record
     * (itemId null, tierIndex 0, percent/base/amount '0.00', dryRun false) only
     * when no record exists. Production binds this to INSERT IGNORE on the
     * invoice_id primary key.
     *
     * @return bool true when this call claimed the invoice, false when a record already existed
     */
    public function claimInvoice(int $invoiceId, int $clientId, string $createdAt): bool;

    /** @param InvoiceRecordRow $row  upsert by invoiceId */
    public function saveInvoiceRecord(array $row): void;

    /**
     * @param 'group'|'product'|'addon' $kind
     * @param list<int> $ids
     * @return array<int,string> id => name, only ids that exist
     */
    public function lookupNames(string $kind, array $ids): array;

    /** @return list<string> currency codes in id order */
    public function currencyCodes(): array;

    /** Runs one DDL statement. ArrayRepository records it in $executed. */
    public function execute(string $sql): void;
}
