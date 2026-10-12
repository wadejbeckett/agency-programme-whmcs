<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/**
 * Adds one negative "Agency Discount" line to an invoice. At most one line per
 * invoice ever: the invoice record is the idempotence guard.
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

        $base = Money::sum(...array_column($qualifying, 'amount'));
        $amount = Money::percentOf($base, $tier->percent);
        if (!Money::isPositive($amount)) {
            $this->log($clientId, $invoiceId, 'zero_amount', ['base' => $base, 'percent' => $tier->percent], $actor);
            return DiscountOutcome::skipped('zero_amount');
        }
        $counted = count($qualifying);
        $taxed = in_array(1, array_column($qualifying, 'taxed'), true) ? 1 : 0;
        $description = $this->config->description(Money::formatPercent($tier->percent), $counted);
        $detail = [
            'base' => $base,
            'amount' => $amount,
            'percent' => $tier->percent,
            'tierIndex' => $tier->index,
            'countedLines' => $counted,
            'pendingLines' => $pendingLines,
            'pendingSpend' => $pendingSpend,
            'description' => $description,
            'source' => $source,
        ];
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if ($this->config->dryRun || $forceDryRun) {
            if (!$forceDryRun) {
                $this->saveRecord($invoiceId, $clientId, null, $tier, $base, $amount, true, $now);
            }
            $this->log($clientId, $invoiceId, 'dry_run', $detail + ['forced' => $forceDryRun], $actor);
            return new DiscountOutcome(DiscountOutcome::DRY_RUN, 'dry_run', $base, $amount, $tier->percent, $counted, null);
        }

        foreach ($this->repo->invoiceItems($invoiceId) as $item) {
            if ($item['type'] === self::ITEM_TYPE) {
                $this->log($clientId, $invoiceId, 'already_processed', ['existingLine' => true], $actor);
                return DiscountOutcome::skipped('already_processed');
            }
        }
        if (!$this->repo->claimInvoice($invoiceId, $clientId, $now)) {
            $this->log($clientId, $invoiceId, 'already_processed', ['claimed' => false], $actor);
            return DiscountOutcome::skipped('already_processed');
        }

        $itemId = $this->repo->addInvoiceItem(
            $invoiceId,
            $clientId,
            self::ITEM_TYPE,
            $clientId,
            $description,
            Money::negate($amount),
            $taxed,
            $invoice['dueDate'],
            $invoice['paymentMethod'],
        );
        $this->saveRecord($invoiceId, $clientId, $itemId, $tier, $base, $amount, false, $now);
        $this->log($clientId, $invoiceId, 'applied', $detail + ['itemId' => $itemId], $actor);
        if ($this->config->activityLog) {
            $this->activity->log(sprintf(
                'Agency Discount: %s%% (%s %s) added to invoice #%d as line %d',
                Money::formatPercent($tier->percent),
                $report->currency,
                $amount,
                $invoiceId,
                $itemId,
            ), $clientId);
        }
        return new DiscountOutcome(DiscountOutcome::APPLIED, 'applied', $base, $amount, $tier->percent, $counted, $itemId);
    }

    private function saveRecord(int $invoiceId, int $clientId, ?int $itemId, Tier $tier, string $base, string $amount, bool $dryRun, string $now): void
    {
        $this->repo->saveInvoiceRecord([
            'invoiceId' => $invoiceId,
            'clientId' => $clientId,
            'itemId' => $itemId,
            'tierIndex' => $tier->index,
            'percent' => $tier->percent,
            'base' => $base,
            'amount' => $amount,
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
