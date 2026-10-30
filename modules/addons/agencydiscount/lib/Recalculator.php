<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** Recomputes agencies' qualifying spend and tier, and records tier changes. */
final class Recalculator
{
    public function __construct(
        private readonly Repository $repo,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ActivityLog $activity,
    ) {
    }

    /**
     * Computes the report; when the client is an approved agency, stores
     * spend and tier and logs tier changes.
     *
     * @throws ClientNotFound
     */
    public function recalculate(int $clientId, string $actor): SpendReport
    {
        $report = (new SpendCalculator($this->repo, $this->config))->forClient($clientId);

        $previous = $this->repo->agency($clientId);
        if ($previous === null || !$previous['approved']) {
            return $report;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $tier = $report->tier;

        // Only the computed columns: an approval change saved meanwhile must survive this write.
        $this->repo->saveCalculation($clientId, [
            'currency' => $report->currency,
            'spend' => $report->monthlySpend,
            'tierIndex' => $tier?->index,
            'tierPercent' => $tier?->percent,
            'countedServices' => count($report->services),
            'countedAddons' => count($report->addons),
            'calculatedAt' => $now,
            'updatedAt' => $now,
        ]);

        if ($previous['tierIndex'] !== $tier?->index) {
            $this->logTierChange($clientId, $actor, $now, $previous, $report);
        }

        if (!$report->currencyHasTiers && ($previous['tierIndex'] !== null || $previous['calculatedAt'] === null)) {
            $this->repo->log([
                'clientId' => $clientId,
                'invoiceId' => null,
                'event' => 'no_tier',
                'detail' => ['currency' => $report->currency, 'spend' => $report->monthlySpend],
                'actor' => $actor,
                'createdAt' => $now,
            ]);
        }

        return $report;
    }

    /**
     * One agency's failure never stops the others: a ClientNotFound is logged as 'client_missing',
     * any other error as 'recalculate_failed', and that agency is skipped. 'client_missing' is
     * written once per state change, not on every run: while the client's newest log entry is
     * already 'client_missing', nothing is added (a client that comes back writes other entries,
     * so its going missing again is logged again).
     *
     * @return list<SpendReport> one per approved agency, ascending client id
     */
    public function recalculateAll(string $actor): array
    {
        $reports = [];
        foreach ($this->repo->approvedAgencies() as $agency) {
            try {
                $reports[] = $this->recalculate($agency['clientId'], $actor);
            } catch (\Throwable $e) {
                if ($e instanceof ClientNotFound && ($this->repo->logEntries($agency['clientId'], 1)[0]['event'] ?? null) === 'client_missing') {
                    continue;
                }
                $this->repo->log([
                    'clientId' => $agency['clientId'],
                    'invoiceId' => null,
                    'event' => $e instanceof ClientNotFound ? 'client_missing' : 'recalculate_failed',
                    'detail' => ['message' => $e->getMessage()],
                    'actor' => $actor,
                    'createdAt' => $this->clock->now()->format('Y-m-d H:i:s'),
                ]);
            }
        }
        return $reports;
    }

    /** @param array{tierIndex:?int, tierPercent:?string} $previous */
    private function logTierChange(int $clientId, string $actor, string $now, array $previous, SpendReport $report): void
    {
        $tier = $report->tier;
        $this->repo->log([
            'clientId' => $clientId,
            'invoiceId' => null,
            'event' => 'tier_changed',
            'detail' => [
                'from' => $previous['tierIndex'],
                'to' => $tier?->index,
                'fromPercent' => $previous['tierPercent'],
                'toPercent' => $tier?->percent,
                'spend' => $report->monthlySpend,
                'currency' => $report->currency,
            ],
            'actor' => $actor,
            'createdAt' => $now,
        ]);

        if ($this->config->activityLog) {
            $this->activity->log(sprintf(
                'Agency Discount: client %d moved from tier %s to tier %s (%s%%) on monthly spend %s %s',
                $clientId,
                $previous['tierIndex'] ?? 'none',
                $tier?->index ?? 'none',
                $tier === null ? '0' : Money::formatPercent($tier->percent),
                $report->currency,
                $report->monthlySpend,
            ), $clientId);
        }
    }
}
