<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

use WHMCS\Database\Capsule;

/**
 * Production Repository on the WHMCS database. Reads go through raw
 * statements (Sql constants) and return stdClass rows, so every field is cast
 * here; writes use the query builder.
 *
 * @phpstan-import-type AgencyRow from Repository
 * @phpstan-import-type InvoiceRecordRow from Repository
 * @phpstan-import-type LogEvent from Repository
 */
final class CapsuleRepository implements Repository
{
    private const CLIENTS = 'mod_agencydiscount_clients';
    private const LOG = 'mod_agencydiscount_log';
    private const INVOICES = 'mod_agencydiscount_invoices';
    private const SETTINGS = 'tbladdonmodules';

    /** From tbladdonmodules, HTML entities decoded. */
    public function settings(): array
    {
        $raw = [];
        foreach (self::select(Sql::SETTINGS, [Module::NAME]) as $row) {
            $raw[(string) $row->setting] = $row->value;
        }
        return self::decodeSettings($raw);
    }

    /**
     * Stored HTML-encoded, exactly as WHMCS stores the values of its own Configure form, so
     * settings() decodes the rows written here and the rows WHMCS writes the same way.
     */
    public function saveSettings(array $settings): void
    {
        foreach ($settings as $setting => $value) {
            Capsule::table(self::SETTINGS)->updateOrInsert(
                ['module' => Module::NAME, 'setting' => $setting],
                ['value' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8')],
            );
        }
    }

    /**
     * WHMCS HTML-encodes request input, so saved settings (notably the tiers JSON)
     * arrive entity-encoded ("&quot;"). Decoding is idempotent for plain values.
     *
     * @param array<string,mixed> $raw setting => stored value
     * @return array<string,string>
     */
    public static function decodeSettings(array $raw): array
    {
        $settings = [];
        foreach ($raw as $setting => $value) {
            $settings[(string) $setting] = html_entity_decode(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $settings;
    }

    /**
     * WHMCS HTML-encodes request input before storing it, so names typed into its admin forms (clients,
     * products, groups, add-ons) sit in the tables as "Edge &amp; DNS". Decoding once here means every
     * page escapes exactly once on output. Idempotent for a name without entities.
     */
    public static function decodeName(mixed $value): string
    {
        return html_entity_decode(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function client(int $clientId): ?array
    {
        $row = self::select(Sql::CLIENT, [$clientId])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $company = trim(self::decodeName($row->companyname));
        return [
            'id' => (int) $row->id,
            'name' => $company !== '' ? $company : trim(self::decodeName($row->firstname) . ' ' . self::decodeName($row->lastname)),
            'currency' => (string) $row->currency,
            'currencyId' => (int) $row->currency_id,
        ];
    }

    public function services(int $clientId): array
    {
        $services = [];
        foreach (self::select(Sql::SERVICES, [$clientId]) as $row) {
            $services[] = [
                'id' => (int) $row->id,
                'productId' => (int) $row->product_id,
                'groupId' => (int) $row->group_id,
                'productName' => self::decodeName($row->product_name),
                'domain' => (string) $row->domain,
                'amount' => self::money($row->amount),
                'billingCycle' => (string) $row->billingcycle,
                'status' => (string) $row->status,
                'nextInvoiceDate' => self::date($row->nextinvoicedate),
                'promoId' => (int) $row->promoid,
            ];
        }
        return $services;
    }

    public function addons(int $clientId): array
    {
        $addons = [];
        foreach (self::select(Sql::ADDONS, [$clientId, $clientId]) as $row) {
            $addons[] = [
                'id' => (int) $row->id,
                'hostingId' => (int) $row->hostingid,
                'addonId' => (int) $row->addonid,
                'addonName' => self::decodeName($row->addon_name),
                'domain' => (string) $row->domain,
                'recurring' => self::money($row->recurring),
                'billingCycle' => (string) $row->billingcycle,
                'status' => (string) $row->status,
                'nextInvoiceDate' => self::date($row->nextinvoicedate),
            ];
        }
        return $addons;
    }

    public function invoice(int $invoiceId): ?array
    {
        $row = self::select(Sql::INVOICE, [$invoiceId])[0] ?? null;
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row->id,
            'clientId' => (int) $row->client_id,
            'status' => (string) $row->status,
            'dueDate' => self::date($row->duedate),
            'paymentMethod' => (string) $row->paymentmethod,
        ];
    }

    public function invoiceItems(int $invoiceId): array
    {
        $items = [];
        foreach (self::select(Sql::INVOICE_ITEMS, [$invoiceId]) as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'relId' => (int) $row->relid,
                'description' => (string) $row->description,
                'amount' => self::money($row->amount),
                'taxed' => (int) $row->taxed,
            ];
        }
        return $items;
    }

    public function addInvoiceItem(int $invoiceId, int $clientId, string $type, int $relId, string $description, string $amount, int $taxed, ?string $dueDate, string $paymentMethod): int
    {
        return (int) Capsule::table('tblinvoiceitems')->insertGetId([
            'invoiceid' => $invoiceId,
            'userid' => $clientId,
            'type' => $type,
            'relid' => $relId,
            'description' => $description,
            'amount' => $amount,
            'taxed' => $taxed,
            'duedate' => $dueDate,
            'paymentmethod' => $paymentMethod,
            'notes' => '',
        ]);
    }

    public function agency(int $clientId): ?array
    {
        $row = Capsule::table(self::CLIENTS)->where('client_id', $clientId)->first();
        return $row === null ? null : self::agencyRow((object) $row);
    }

    public function approvedAgencies(): array
    {
        $rows = [];
        foreach (Capsule::table(self::CLIENTS)->where('approved', 1)->orderBy('client_id')->get() as $row) {
            $rows[] = self::agencyRow((object) $row);
        }
        return $rows;
    }

    public function saveAgency(array $row): void
    {
        Capsule::table(self::CLIENTS)->updateOrInsert(
            ['client_id' => $row['clientId']],
            [
                'approved' => $row['approved'] ? 1 : 0,
                'note' => $row['note'],
                'approved_by' => self::truncate($row['approvedBy']),
                'approved_at' => $row['approvedAt'],
                'effective_from' => $row['effectiveFrom'],
                'currency' => $row['currency'],
                'spend' => $row['spend'],
                'tier_index' => $row['tierIndex'],
                'tier_percent' => $row['tierPercent'],
                'counted_services' => $row['countedServices'],
                'counted_addons' => $row['countedAddons'],
                'calculated_at' => $row['calculatedAt'],
                'updated_at' => $row['updatedAt'],
            ],
        );
    }

    public function saveCalculation(int $clientId, array $calculation): void
    {
        Capsule::table(self::CLIENTS)->where('client_id', $clientId)->update([
            'currency' => $calculation['currency'],
            'spend' => $calculation['spend'],
            'tier_index' => $calculation['tierIndex'],
            'tier_percent' => $calculation['tierPercent'],
            'counted_services' => $calculation['countedServices'],
            'counted_addons' => $calculation['countedAddons'],
            'calculated_at' => $calculation['calculatedAt'],
            'updated_at' => $calculation['updatedAt'],
        ]);
    }

    public function log(array $event): void
    {
        Capsule::table(self::LOG)->insert([
            'client_id' => $event['clientId'],
            'invoice_id' => $event['invoiceId'],
            'event' => $event['event'],
            'detail' => json_encode($event['detail'], JSON_THROW_ON_ERROR),
            'actor' => self::truncate($event['actor']),
            'created_at' => $event['createdAt'],
        ]);
    }

    public function pruneLog(string $before): int
    {
        return (int) Capsule::table(self::LOG)->where('created_at', '<', $before)->delete();
    }

    public function logEntries(?int $clientId, int $limit, int $offset = 0): array
    {
        $query = Capsule::table(self::LOG);
        if ($clientId !== null) {
            $query = $query->where('client_id', $clientId);
        }
        $entries = [];
        foreach ($query->orderBy('id', 'desc')->offset($offset)->limit($limit)->get() as $row) {
            $row = (object) $row;
            try {
                $detail = json_decode((string) $row->detail, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $detail = ['_raw' => (string) $row->detail];
            }
            $entries[] = [
                'id' => (int) $row->id,
                'clientId' => $row->client_id === null ? null : (int) $row->client_id,
                'invoiceId' => $row->invoice_id === null ? null : (int) $row->invoice_id,
                'event' => (string) $row->event,
                'detail' => is_array($detail) ? $detail : [],
                'actor' => (string) $row->actor,
                'createdAt' => (string) $row->created_at,
            ];
        }
        return $entries;
    }

    public function invoiceRecord(int $invoiceId): ?array
    {
        return $this->invoiceRecordLines($invoiceId)[0] ?? null;
    }

    public function invoiceRecordLines(int $invoiceId): array
    {
        $records = [];
        foreach (Capsule::table(self::INVOICES)->where('invoice_id', $invoiceId)->orderBy('line')->get() as $row) {
            $row = (object) $row;
            $records[] = [
                'invoiceId' => (int) $row->invoice_id,
                'line' => (int) $row->line,
                'clientId' => (int) $row->client_id,
                'itemId' => $row->item_id === null ? null : (int) $row->item_id,
                'taxed' => (int) $row->taxed === 1,
                'tierIndex' => (int) $row->tier_index,
                'percent' => self::money($row->percent),
                'base' => self::money($row->base),
                'amount' => self::money($row->amount),
                'dryRun' => (int) $row->dry_run === 1,
                'createdAt' => (string) $row->created_at,
            ];
        }
        return $records;
    }

    public function claimInvoice(int $invoiceId, int $clientId, string $createdAt): bool
    {
        return Capsule::connection()->affectingStatement(Sql::CLAIM_INVOICE, [$invoiceId, $clientId, $createdAt]) === 1;
    }

    public function saveInvoiceRecord(array $row): void
    {
        Capsule::table(self::INVOICES)->updateOrInsert(
            ['invoice_id' => $row['invoiceId'], 'line' => $row['line']],
            [
                'client_id' => $row['clientId'],
                'item_id' => $row['itemId'],
                'taxed' => $row['taxed'] ? 1 : 0,
                'tier_index' => $row['tierIndex'],
                'percent' => $row['percent'],
                'base' => $row['base'],
                'amount' => $row['amount'],
                'dry_run' => $row['dryRun'] ? 1 : 0,
                'created_at' => $row['createdAt'],
            ],
        );
    }

    public function lookupNames(string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $sql = match ($kind) {
            'group' => Sql::GROUP_NAMES,
            'product' => Sql::PRODUCT_NAMES,
            'addon' => Sql::ADDON_NAMES,
        };
        $names = [];
        foreach (self::select(sprintf($sql, Sql::placeholders($ids)), array_values($ids)) as $row) {
            $names[(int) $row->id] = self::decodeName($row->name);
        }
        return $names;
    }

    public function currencyCodes(): array
    {
        return array_column($this->currencies(), 'code');
    }

    public function productGroups(): array
    {
        $groups = [];
        foreach (self::select(Sql::PRODUCT_GROUPS, []) as $row) {
            $groups[] = [
                'id' => (int) $row->id,
                'name' => self::decodeName($row->name),
                'hidden' => (int) $row->hidden === 1,
                'activeServices' => (int) $row->active_services,
            ];
        }
        return $groups;
    }

    public function products(): array
    {
        $products = [];
        foreach (self::select(Sql::PRODUCTS, []) as $row) {
            $products[] = ['id' => (int) $row->id, 'groupId' => (int) $row->group_id, 'name' => self::decodeName($row->name)];
        }
        return $products;
    }

    public function addonCatalog(): array
    {
        $addons = [];
        foreach (self::select(Sql::ADDON_CATALOG, []) as $row) {
            $addons[] = ['id' => (int) $row->id, 'name' => self::decodeName($row->name)];
        }
        return $addons;
    }

    public function currencies(): array
    {
        $currencies = [];
        foreach (self::select(Sql::CURRENCIES, []) as $row) {
            $currencies[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'rate' => bcadd(is_numeric($row->rate) ? (string) $row->rate : '1', '0', 5),
                'isDefault' => (int) $row->is_default === 1,
            ];
        }
        return $currencies;
    }

    public function invoicesChecked(): int
    {
        return (int) Capsule::table(self::LOG)->whereNotNull('invoice_id')->distinct()->count('invoice_id');
    }

    public function adminRoleId(int $adminId): ?int
    {
        $row = self::select(Sql::ADMIN_ROLE, [$adminId])[0] ?? null;
        return $row === null ? null : (int) $row->roleid;
    }

    public function appliedDiscountTotal(int $clientId, string $fromDate): string
    {
        $row = self::select(Sql::APPLIED_DISCOUNT_TOTAL, [$clientId, $fromDate])[0] ?? null;
        return self::money($row?->total);
    }

    public function transaction(callable $work): mixed
    {
        return Capsule::connection()->transaction(static fn () => $work());
    }

    public function execute(string $sql): void
    {
        Capsule::connection()->statement($sql);
    }

    /**
     * @param list<int|string> $bindings
     * @return list<\stdClass>
     */
    private static function select(string $sql, array $bindings): array
    {
        $rows = [];
        foreach (Capsule::connection()->select($sql, $bindings) as $row) {
            $rows[] = (object) $row;
        }
        return $rows;
    }

    /** @return AgencyRow */
    private static function agencyRow(\stdClass $row): array
    {
        return [
            'clientId' => (int) $row->client_id,
            'approved' => (int) $row->approved === 1,
            'note' => (string) $row->note,
            'approvedBy' => (string) $row->approved_by,
            'approvedAt' => self::date($row->approved_at),
            'effectiveFrom' => self::date($row->effective_from),
            'currency' => (string) $row->currency,
            'spend' => self::money($row->spend),
            'tierIndex' => $row->tier_index === null ? null : (int) $row->tier_index,
            'tierPercent' => $row->tier_percent === null ? null : self::money($row->tier_percent),
            'countedServices' => (int) $row->counted_services,
            'countedAddons' => (int) $row->counted_addons,
            'calculatedAt' => self::date($row->calculated_at),
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /**
     * The first $max characters (the actor columns are VARCHAR(64)), cut on a UTF-8 character
     * boundary without needing mbstring; a string that is not valid UTF-8 is cut at $max bytes.
     */
    public static function truncate(string $value, int $max = 64): string
    {
        return preg_match('/^.{0,' . $max . '}/us', $value, $m) === 1 ? $m[0] : substr($value, 0, $max);
    }

    /** Two-place decimal string; NULL or non-numeric becomes 0.00. */
    private static function money(mixed $value): string
    {
        return bcadd(is_numeric($value) ? (string) $value : '0', '0', 2);
    }

    /** 'Y-m-d' or 'Y-m-d H:i:s' as stored; NULL, '' and zero dates ('0000-00-00…') become null. */
    private static function date(mixed $value): ?string
    {
        $date = $value === null ? '' : (string) $value;
        return $date === '' || str_starts_with($date, '0000-00-00') ? null : $date;
    }
}
