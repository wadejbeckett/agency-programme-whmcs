<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** What InvoiceDiscounter::apply did with one invoice. */
final class DiscountOutcome
{
    public const APPLIED = 'applied';
    public const DRY_RUN = 'dry_run';
    public const SKIPPED = 'skipped';

    /**
     * @param string  $status  one of the constants
     * @param string  $reason  'applied' | 'dry_run' | 'invoice_not_found' | 'already_processed' | 'not_approved' | 'no_tier' | 'not_yet_effective' | 'no_qualifying_lines' | 'zero_amount'
     * @param ?string $base    sum of qualifying lines, or null when skipped before computing
     * @param ?string $amount  positive discount amount, or null
     * @param ?string $percent '5.00' or null
     * @param ?int    $itemId  inserted tblinvoiceitems id (the first, when there are two lines), or null
     * @param list<array{taxed:int, base:string, amount:string, countedLines:int, description:string, itemId:?int}> $lines
     *        one entry per discount line: one, or two (untaxed first) when the qualifying lines mix tax treatments;
     *        base, amount and countedLines above are their totals
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?string $base,
        public readonly ?string $amount,
        public readonly ?string $percent,
        public readonly int $countedLines,
        public readonly ?int $itemId,
        public readonly array $lines = [],
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason, null, null, null, 0, null);
    }
}
