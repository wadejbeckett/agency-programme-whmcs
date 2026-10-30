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

    /**
     * Removes change log entries older than the retention setting (none when it is 0) and returns
     * how many went. Approvals and invoice records are not touched.
     */
    public function pruneLog(): int
    {
        $months = $this->config->logRetentionMonths;
        if ($months <= 0) {
            return 0;
        }
        $before = $this->clock->now()->modify(sprintf('-%d months', $months))->format('Y-m-d H:i:s');
        return $this->repo->pruneLog($before);
    }

    public function discounter(): InvoiceDiscounter
    {
        return new InvoiceDiscounter($this->repo, $this->config, $this->recalculator(), $this->clock, $this->activity);
    }
}
