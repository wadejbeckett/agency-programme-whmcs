<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * Adds one negative "Agency Discount" line to an invoice, or two when the
 * qualifying lines mix taxed and untaxed items (WHMCS taxes per line, so one
 * taxed line would lower the tax by the discount on untaxed items too). Each
 * invoice is discounted once ever: the invoice record is the idempotence guard.
 */
final class InvoiceDiscounter
{
    public const ITEM_TYPE = 'AgencyDiscount';

    public function __construct(
        private readonly Repository $repo,
        private readonly Config $config,
        private readonly Recalculator $recalculator,
        private readonly Clock $clock,
        private readonly ActivityLog $activity,
    ) {
    }

    /**
     * @param bool $forceDryRun true from the CLI rehearsal and the admin preview: never writes to tblinvoiceitems even when the setting is off
     * @throws ClientNotFound
     */
    public function apply(int $invoiceId, string $source, string $actor, bool $forceDryRun = false): DiscountOutcome
    {
        $invoice = $this->repo->invoice($invoiceId);
        if ($invoice === null) {
            $this->log(null, $invoiceId, 'invoice_not_found', [], $actor);
            return DiscountOutcome::skipped('invoice_not_found');
        }
        $clientId = $invoice['clientId'];

        $existing = $this->repo->invoiceRecord($invoiceId);
        if ($existing !== null) {
            $this->log($clientId, $invoiceId, 'already_processed', ['dryRun' => $existing['dryRun']], $actor);
            return DiscountOutcome::skipped('already_processed');
        }

        $agency = $this->repo->agency($clientId);
        if ($agency === null || !$agency['approved']) {
            return DiscountOutcome::skipped('not_approved');
        }

        $report = $this->recalculator->recalculate($clientId, $actor);
        $items = $this->repo->invoiceItems($invoiceId);

        // A new order is invoiced while its items are still Pending, outside the counted spend. With
        // "Discount new orders" on, the pending qualifying items billed on this invoice join the
        // discount base, and their monthly equivalent joins the spend the tier is chosen on, so a
        // first large order can earn a tier. The stored agency tier stays on counted spend alone.
        $pendingServices = $this->config->newOrders ? $report->pendingServiceMonthly() : [];
        $pendingAddons = $this->config->newOrders ? $report->pendingAddonMonthly() : [];
        $pendingOnInvoice = [];
        foreach ($items as $item) {
            if ($item['type'] === 'Hosting' && isset($pendingServices[$item['relId']])) {
                $pendingOnInvoice['s' . $item['relId']] = $pendingServices[$item['relId']];
            } elseif ($item['type'] === 'Addon' && isset($pendingAddons[$item['relId']])) {
                $pendingOnInvoice['a' . $item['relId']] = $pendingAddons[$item['relId']];
            }
        }
        $pendingSpend = Money::sum(...array_values($pendingOnInvoice));
        $tier = $pendingOnInvoice === []
            ? $report->tier
            : $this->config->tiers->tierFor($report->currency, Money::sum($report->monthlySpend, $pendingSpend));
        if ($tier === null) {
            $this->log($clientId, $invoiceId, 'no_tier', [
                'spend' => $report->monthlySpend,
                'pendingSpend' => $pendingSpend,
                'currency' => $report->currency,
                'currencyHasTiers' => $report->currencyHasTiers,
            ], $actor);
            return DiscountOutcome::skipped('no_tier');
        }

        // The start date is compared with the invoice's due date (the day the billed period starts),
        // so a renewal generated in advance for a period after the start date is discounted.
        $today = $this->clock->now()->format('Y-m-d');
        $dueDate = $invoice['dueDate'];
        $effectiveFrom = $agency['effectiveFrom'];
        if ($effectiveFrom !== null && substr($dueDate ?? $today, 0, 10) < substr($effectiveFrom, 0, 10)) {
            $this->log($clientId, $invoiceId, 'not_yet_effective', [
                'effectiveFrom' => $effectiveFrom,
                'dueDate' => $dueDate,
                'today' => $today,
                'source' => $source,
            ], $actor);
            return DiscountOutcome::skipped('not_yet_effective');
        }

        // A promotion only reduced this invoice when it carries a PromoHosting line for the service;
        // the service's promotion id alone can outlive the promotion's recurring period.
        $promoted = [];
        foreach ($items as $item) {
            if ($item['type'] === 'PromoHosting') {
                $promoted[$item['relId']] = true;
            }
        }
        // A counted item and a pending item are never the same item (SpendCalculator keeps them
        // exclusive), and each line is examined once, so no line can enter the base twice.
        $serviceIds = $report->serviceIds();
        $addonIds = $report->addonIds();
        $qualifying = [];
        $pendingLines = 0;
        foreach ($items as $item) {
            if (!Money::isPositive($item['amount'])) {
                continue;
            }
            $isPending = ($item['type'] === 'Hosting' && isset($pendingServices[$item['relId']]))
                || ($item['type'] === 'Addon' && isset($pendingAddons[$item['relId']]));
            $isService = $item['type'] === 'Hosting'
                && (in_array($item['relId'], $serviceIds, true) || isset($pendingServices[$item['relId']]))
                && ($this->config->stackWithPromotions || !isset($promoted[$item['relId']]));
            $isAddon = $item['type'] === 'Addon'
                && (in_array($item['relId'], $addonIds, true) || isset($pendingAddons[$item['relId']]));
            if ($isService || $isAddon) {
                $qualifying[] = $item;
                if ($isPending) {
                    $pendingLines++;
                }
            }
        }
        if ($qualifying === []) {
            $this->log($clientId, $invoiceId, 'no_qualifying_lines', ['itemTypes' => array_column($items, 'type')], $actor);
            return DiscountOutcome::skipped('no_qualifying_lines');
        }

        // One discount line per tax treatment, untaxed first; an invoice whose qualifying lines share
        // one treatment gets exactly one line, as before 0.2.1.
        $groups = [];
        foreach ($qualifying as $item) {
            $groups[$item['taxed'] === 1 ? 1 : 0][] = $item;
        }
        ksort($groups);
        $lines = [];
        foreach ($groups as $taxed => $groupItems) {
            $lineBase = Money::sum(...array_column($groupItems, 'amount'));
            $lineAmount = Money::percentOf($lineBase, $tier->percent);
            if (!Money::isPositive($lineAmount)) {
                continue;
            }
            $lines[] = [
                'taxed' => $taxed,
                'base' => $lineBase,
                'amount' => $lineAmount,
                'countedLines' => count($groupItems),
                'description' => $this->config->description(Money::formatPercent($tier->percent), count($groupItems)),
                'itemId' => null,
            ];
        }
        if ($lines === []) {
            $this->log($clientId, $invoiceId, 'zero_amount', ['base' => Money::sum(...array_column($qualifying, 'amount')), 'percent' => $tier->percent], $actor);
            return DiscountOutcome::skipped('zero_amount');
        }
        // A group whose discount rounds to nothing gets no line, so its amounts are not in the base either.
        $base = Money::sum(...array_column($lines, 'base'));
        $amount = Money::sum(...array_column($lines, 'amount'));
        $counted = array_sum(array_column($lines, 'countedLines'));
        $detail = [
            'base' => $base,
            'amount' => $amount,
            'percent' => $tier->percent,
            'tierIndex' => $tier->index,
            'countedLines' => $counted,
            'pendingLines' => $pendingLines,
            'pendingSpend' => $pendingSpend,
        ];
        if (count($lines) === 1) {
            $detail['description'] = $lines[0]['description'];
        }
        $detail['source'] = $source;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if ($this->config->dryRun || $forceDryRun) {
            if (!$forceDryRun) {
                foreach ($lines as $n => $line) {
                    $this->saveRecord($invoiceId, $n, $clientId, $line, $tier, true, $now);
                }
            }
            $this->log($clientId, $invoiceId, 'dry_run', $detail + self::linesDetail($lines) + ['forced' => $forceDryRun], $actor);
            return new DiscountOutcome(DiscountOutcome::DRY_RUN, 'dry_run', $base, $amount, $tier->percent, $counted, null, $lines);
        }

        foreach ($this->repo->invoiceItems($invoiceId) as $item) {
            if ($item['type'] === self::ITEM_TYPE) {
                $this->log($clientId, $invoiceId, 'already_processed', ['existingLine' => true], $actor);
                return DiscountOutcome::skipped('already_processed');
            }
        }
        // Claim, discount lines and their records commit together: a failed insert rolls the claim back,
        // so the invoice is not left with a placeholder record that blocks a later attempt.
        $lines = $this->repo->transaction(function () use ($invoiceId, $clientId, $lines, $tier, $invoice, $now): ?array {
            if (!$this->repo->claimInvoice($invoiceId, $clientId, $now)) {
                return null;
            }
            foreach ($lines as $n => $line) {
                $lines[$n]['itemId'] = $this->repo->addInvoiceItem(
                    $invoiceId,
                    $clientId,
                    self::ITEM_TYPE,
                    $clientId,
                    $line['description'],
                    Money::negate($line['amount']),
                    $line['taxed'],
                    $invoice['dueDate'],
                    $invoice['paymentMethod'],
                );
                $this->saveRecord($invoiceId, $n, $clientId, $lines[$n], $tier, false, $now);
            }
            return $lines;
        });
        if ($lines === null) {
            $this->log($clientId, $invoiceId, 'already_processed', ['claimed' => false], $actor);
            return DiscountOutcome::skipped('already_processed');
        }
        $itemIds = array_map(static fn (array $line): int => (int) $line['itemId'], $lines);
        $applied = $detail + self::linesDetail($lines);
        if (count($lines) === 1) {
            $applied['itemId'] = $itemIds[0];
        }
        $this->log($clientId, $invoiceId, 'applied', $applied, $actor);
        if ($this->config->activityLog) {
            $this->activity->log(sprintf(
                'Agency Discount: %s%% (%s %s) added to invoice #%d as %s %s',
                Money::formatPercent($tier->percent),
                $report->currency,
                $amount,
                $invoiceId,
                count($itemIds) === 1 ? 'line' : 'lines',
                implode(' and ', $itemIds),
            ), $clientId);
        }
        return new DiscountOutcome(DiscountOutcome::APPLIED, 'applied', $base, $amount, $tier->percent, $counted, $itemIds[0], $lines);
    }

    /**
     * The per-line detail of a change-log entry; empty for a single line, so its entry keeps the
     * shape every earlier release wrote.
     *
     * @param list<array{taxed:int, base:string, amount:string, countedLines:int, description:string, itemId:?int}> $lines
     * @return array{lines?: list<array{taxed:int, base:string, amount:string, countedLines:int, description:string, itemId:?int}>}
     */
    private static function linesDetail(array $lines): array
    {
        return count($lines) > 1 ? ['lines' => $lines] : [];
    }

    /** @param array{taxed:int, base:string, amount:string, countedLines:int, description:string, itemId:?int} $line */
    private function saveRecord(int $invoiceId, int $n, int $clientId, array $line, Tier $tier, bool $dryRun, string $now): void
    {
        $this->repo->saveInvoiceRecord([
            'invoiceId' => $invoiceId,
            'line' => $n,
            'clientId' => $clientId,
            'itemId' => $line['itemId'],
            'taxed' => $line['taxed'] === 1,
            'tierIndex' => $tier->index,
            'percent' => $tier->percent,
            'base' => $line['base'],
            'amount' => $line['amount'],
            'dryRun' => $dryRun,
            'createdAt' => $now,
        ]);
    }

    /** @param array<string,mixed> $detail */
    private function log(?int $clientId, int $invoiceId, string $event, array $detail, string $actor): void
    {
        $this->repo->log([
            'clientId' => $clientId,
            'invoiceId' => $invoiceId,
            'event' => $event,
            'detail' => $detail,
            'actor' => $actor,
            'createdAt' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
