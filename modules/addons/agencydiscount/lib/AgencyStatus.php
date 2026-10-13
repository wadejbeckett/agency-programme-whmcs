<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

use AgencyDiscount\Admin\Preview;

/**
 * One approved agency as the dashboards show it: the live spend report, the stored row, where the
 * spend sits in its tier band, and the invoices expected in the preview window. Built once per
 * agency and read by the overview, the home widget, the client summary card, the profile box and
 * the client area, so every surface shows the same numbers.
 *
 * @phpstan-import-type AgencyRow from Repository
 * @phpstan-type UpcomingRow array{date:string, base:string, percent:?string, discount:string, items:int}
 */
final class AgencyStatus
{
    /** An agency whose shortfall to the next threshold is at most this percentage of that threshold is close to it. */
    public const NEAR_TIER_PERCENT = '5';
    /** How far ahead the next invoices are previewed, in days. */
    public const PREVIEW_DAYS = 35;

    /**
     * @param AgencyRow $agency
     * @param list<UpcomingRow> $upcoming
     */
    private function __construct(
        public readonly SpendReport $report,
        public readonly array $agency,
        public readonly array $upcoming,
        private readonly string $today,
    ) {
    }

    /**
     * @return self|null null when the client is not an approved agency
     * @throws ClientNotFound when the agency's client no longer exists in WHMCS
     */
    public static function of(Module $module, int $clientId): ?self
    {
        $agency = $module->repo->agency($clientId);
        if ($agency === null || !$agency['approved']) {
            return null;
        }
        return self::build($module, $agency);
    }

    /**
     * Every approved agency whose client still exists, ascending client id.
     *
     * @param list<int> $missing filled with the client ids WHMCS no longer has
     * @return list<self>
     */
    public static function all(Module $module, array &$missing = []): array
    {
        $statuses = [];
        foreach ($module->repo->approvedAgencies() as $agency) {
            try {
                $statuses[] = self::build($module, $agency);
            } catch (ClientNotFound) {
                $missing[] = $agency['clientId'];
            }
        }
        return $statuses;
    }

    /** @param AgencyRow $agency */
    private static function build(Module $module, array $agency): self
    {
        $report = $module->spend()->forClient($agency['clientId']);
        $now = $module->clock->now();
        return new self($report, $agency, Preview::upcoming($report, $now, self::PREVIEW_DAYS, $agency['effectiveFrom']), $now->format('Y-m-d'));
    }

    /** @return UpcomingRow|null the first invoice expected in the preview window */
    public function nextInvoice(): ?array
    {
        return $this->upcoming[0] ?? null;
    }

    /** The discount expected on every invoice in the preview window. */
    public function discountInWindow(): string
    {
        return Money::sum(...array_column($this->upcoming, 'discount'));
    }

    /** What the agency must add to reach the next tier; null at the top tier or without tiers. */
    public function shortfall(): ?string
    {
        return $this->report->shortfallToNextTier();
    }

    /**
     * How far the spend has come through its band, from the current tier's threshold (0 below the first
     * tier) to the next one: 0 to 100, 100 at the top tier, null when the currency has no tiers.
     */
    public function progressPercent(): ?int
    {
        $report = $this->report;
        if (!$report->currencyHasTiers) {
            return null;
        }
        if ($report->nextTier === null) {
            return 100;
        }
        $floor = $report->tier?->above ?? '0.00';
        $range = bcsub($report->nextTier->above, $floor, 2);
        if (!Money::isPositive($range)) {
            return 0;
        }
        $percent = (int) bcdiv(bcmul(bcsub($report->monthlySpend, $floor, 2), '100', 2), $range, 0);
        return max(0, min(100, $percent));
    }

    /** Within NEAR_TIER_PERCENT of the next threshold. */
    public function isNearNextTier(): bool
    {
        $next = $this->report->nextTier;
        $shortfall = $this->shortfall();
        if ($next === null || $shortfall === null || !Money::isPositive($next->above)) {
            return false;
        }
        return bccomp($shortfall, Money::percentOf($next->above, self::NEAR_TIER_PERCENT), 2) <= 0;
    }

    /** The start date when it is still ahead; a start date already reached changes nothing. */
    public function startsOn(): ?string
    {
        $from = $this->agency['effectiveFrom'];
        if ($from === null) {
            return null;
        }
        $date = substr($from, 0, 10);
        return $date > $this->today ? $date : null;
    }

    /** Approved, but the discount only starts on a future date. */
    public function isWaiting(): bool
    {
        return $this->startsOn() !== null;
    }
}
