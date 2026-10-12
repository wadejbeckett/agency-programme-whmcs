<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\Money;
use AgencyDiscount\SpendReport;

/** What the next invoices of an agency would be discounted by, from its counted items' next due dates. */
final class Preview
{
    /**
     * Groups an agency's counted services and add-ons by nextInvoiceDate within [$from, $from + $days]
     * (inclusive) and returns one row per date: the recurring amounts billed on that date (full cycle
     * amount, not monthly), the discount at the report's tier, and the item count. Items without a next
     * invoice date are ignored. Rows dated before $effectiveFrom (the agency's start date) get no percent
     * and a 0.00 discount.
     *
     * This is an estimate: services on a promotion are included, because whether the promotion still
     * reduces an invoice is only known from that invoice's lines, which InvoiceDiscounter checks.
     *
     * @return list<array{date:string, base:string, percent:?string, discount:string, items:int}> ascending date
     */
    public static function upcoming(SpendReport $report, \DateTimeImmutable $from, int $days = 35, ?string $effectiveFrom = null): array
    {
        $first = $from->format('Y-m-d');
        $last = $from->modify('+' . $days . ' days')->format('Y-m-d');
        $starts = $effectiveFrom === null ? null : substr($effectiveFrom, 0, 10);

        /** @var array<string, list<string>> $amountsByDate */
        $amountsByDate = [];
        foreach ($report->services as $service) {
            self::collect($amountsByDate, $service['nextInvoiceDate'], $service['amount'], $first, $last);
        }
        foreach ($report->addons as $addon) {
            self::collect($amountsByDate, $addon['nextInvoiceDate'], $addon['amount'], $first, $last);
        }
        ksort($amountsByDate);

        $rows = [];
        foreach ($amountsByDate as $date => $amounts) {
            $date = (string) $date;
            $percent = $starts !== null && $date < $starts ? null : $report->tier?->percent;
            $base = Money::sum(...$amounts);
            $rows[] = [
                'date' => $date,
                'base' => $base,
                'percent' => $percent,
                'discount' => $percent === null ? '0.00' : Money::percentOf($base, $percent),
                'items' => count($amounts),
            ];
        }
        return $rows;
    }

    /** @param array<string, list<string>> $amountsByDate */
    private static function collect(array &$amountsByDate, ?string $date, string $amount, string $first, string $last): void
    {
        if ($date === null || $date < $first || $date > $last) {
            return;
        }
        $amountsByDate[$date][] = $amount;
    }
}
