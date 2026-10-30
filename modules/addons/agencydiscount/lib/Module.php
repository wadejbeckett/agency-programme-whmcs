<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** The module's object graph: one repository, configuration, clock and activity log shared by every service. */
final class Module
{
    public const NAME = 'agencydiscount';
    public const VERSION = '0.3.0';

    public function __construct(
        public readonly Repository $repo,
        public readonly Config $config,
        public readonly Clock $clock,
        public readonly ActivityLog $activity,
    ) {
    }

    /**
     * Builds the production container from the module's WHMCS settings.
     *
     * @throws ConfigException
     */
    public static function boot(): self
    {
        $repo = new CapsuleRepository();
        return new self($repo, Config::fromSettings($repo->settings()), new SystemClock(), new WhmcsActivityLog());
    }

    public function spend(): SpendCalculator
    {
        return new SpendCalculator($this->repo, $this->config);
    }

    public function recalculator(): Recalculator
    {
        return new Recalculator($this->repo, $this->config, $this->clock, $this->activity);
    }

    /** Rows removed per DELETE while pruning the change log. */
    public const PRUNE_BATCH = 1000;

    /**
     * Removes change log entries older than the retention setting (none when it is 0), in batches
     * of PRUNE_BATCH rows, writes one 'log_pruned' entry with the count and the cut-off when anything
     * went, and returns how many went. The cut-off is the same day of the month $months back,
     * clamped to that month's last day (31 March less one month is 28 February, not 3 March).
     * Approvals and invoice records are not touched.
     */
    public function pruneLog(): int
    {
        $months = $this->config->logRetentionMonths;
        if ($months <= 0) {
            return 0;
        }
        $now = $this->clock->now();
        $target = $now->modify('first day of -' . $months . ' months');
        $before = $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min((int) $now->format('j'), (int) $target->format('t')))->format('Y-m-d H:i:s');
        $total = 0;
        do {
            $batch = $this->repo->pruneLog($before, self::PRUNE_BATCH);
            $total += $batch;
        } while ($batch >= self::PRUNE_BATCH);
        if ($total > 0) {
            $this->repo->log([
                'clientId' => null,
                'invoiceId' => null,
                'event' => 'log_pruned',
                'detail' => ['count' => $total, 'before' => $before, 'months' => $months],
                'actor' => 'cron:daily',
                'createdAt' => $now->format('Y-m-d H:i:s'),
            ]);
        }
        return $total;
    }

    public function discounter(): InvoiceDiscounter
    {
        return new InvoiceDiscounter($this->repo, $this->config, $this->recalculator(), $this->clock, $this->activity);
    }
}
