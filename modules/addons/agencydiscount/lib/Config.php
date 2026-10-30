<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** The addon's settings, parsed and validated. */
final class Config
{
    /** The invoice line text shipped before 0.2.1, which said "1 qualifying items"; the upgrade replaces it when it is still stored unchanged. */
    public const OLD_DEFAULT_DESCRIPTION = 'Agency discount {percent}% on {count} qualifying items';
    public const DEFAULT_DESCRIPTION = 'Agency discount {percent}% on {count} qualifying {items}';

    /**
     * The rows the engine needs and the Settings tab writes; when one is absent (a fresh install
     * before the first Settings save, a row removed by hand) its default applies and the Overview
     * and the daily cron say so, in these words, so a discount never stops silently.
     */
    public const DEFAULT_WORDS = [
        'tiers' => 'no tiers',
        'dry_run' => 'dry-run on',
        'product_groups' => 'no product groups count',
        'products' => 'no products count',
        'addons' => 'no add-ons count',
    ];

    private const DEFAULTS = [
        'tiers' => '{}',
        'product_groups' => '',
        'products' => '',
        'addons' => '',
        'statuses' => 'Active',
        'dry_run' => 'on',
        'stack_with_promotions' => '',
        'new_orders' => 'on',
        'description' => self::DEFAULT_DESCRIPTION,
        'delete_data' => '',
        'activity_log' => 'on',
        'log_retention_months' => '24',
    ];

    /**
     * @param list<int> $productGroupIds
     * @param list<int> $productIds
     * @param list<int> $addonIds
     * @param list<string> $statuses
     * @param list<string> $missingKeys the DEFAULT_WORDS keys with no stored row, in that order
     */
    private function __construct(
        public readonly Tiers $tiers,
        public readonly array $productGroupIds,
        public readonly array $productIds,
        public readonly array $addonIds,
        public readonly array $statuses,
        public readonly bool $dryRun,
        public readonly bool $stackWithPromotions,
        public readonly bool $newOrders,
        public readonly string $descriptionTemplate,
        public readonly bool $deleteData,
        public readonly bool $activityLog,
        public readonly int $logRetentionMonths,
        public readonly array $missingKeys = [],
    ) {
    }

    /**
     * "no stored value for tiers, dry_run; the defaults apply (no tiers, dry-run on)", or null when
     * every engine row is stored. The Overview item and the daily cron line are built from it.
     */
    public function defaultsNote(): ?string
    {
        if ($this->missingKeys === []) {
            return null;
        }
        $words = array_map(static fn (string $key): string => self::DEFAULT_WORDS[$key], $this->missingKeys);
        return 'no stored value for ' . implode(', ', $this->missingKeys) . '; the defaults apply (' . implode(', ', $words) . ')';
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @param array<string, mixed> $settings raw addon settings; missing keys take their defaults
     * @throws ConfigException listing every problem found
     */
    public static function fromSettings(array $settings): self
    {
        $s = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $settings[$key] ?? $default;
            $s[$key] = is_scalar($value) ? (string) $value : $default;
        }
        $missing = array_values(array_filter(array_keys(self::DEFAULT_WORDS), static fn (string $key): bool => !isset($settings[$key])));

        $problems = [];

        try {
            $tiers = Tiers::fromJson($s['tiers']);
        } catch (ConfigException $e) {
            $tiers = null;
            array_push($problems, ...$e->problems());
        }

        $productGroupIds = self::ids('product_groups', $s['product_groups'], $problems);
        $productIds = self::ids('products', $s['products'], $problems);
        $addonIds = self::ids('addons', $s['addons'], $problems);

        $statuses = self::split($s['statuses']);
        if ($statuses === []) {
            $problems[] = 'statuses: at least one status is required';
        }

        $description = trim($s['description']);
        if ($description === '') {
            $problems[] = 'description: must not be empty';
        }

        if ($problems !== [] || $tiers === null) {
            throw new ConfigException($problems);
        }

        return new self(
            $tiers,
            $productGroupIds,
            $productIds,
            $addonIds,
            $statuses,
            self::yesNo($s['dry_run'], true),
            self::yesNo($s['stack_with_promotions'], false),
            self::yesNo($s['new_orders'], false),
            $description,
            self::yesNo($s['delete_data'], false),
            self::yesNo($s['activity_log'], true),
            self::retentionMonths($s['log_retention_months']),
            $missing,
        );
    }

    /**
     * Months of change log to keep: a whole number from 0 to 600, where 0 keeps the log forever.
     * Anything else also keeps it forever, so a mistyped value never deletes history and never
     * blocks the discounts the way a configuration problem does.
     */
    public static function retentionMonths(string $value): int
    {
        $v = trim($value);
        if (preg_match('/^\d{1,3}$/', $v) !== 1 || (int) $v > 600) {
            return 0;
        }
        return (int) $v;
    }

    /**
     * A yes/no setting: "on", "yes", "1", "true" (any case, trimmed) are yes; "", "off", "0", "no" are
     * no; anything else gives $safe, so an unexpected value never turns a safeguard off (or, for
     * delete_data, never turns a destructive action on).
     */
    public static function yesNo(mixed $value, bool $safe): bool
    {
        $v = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        if (in_array($v, ['on', 'yes', '1', 'true'], true)) {
            return true;
        }
        if (in_array($v, ['', 'off', '0', 'no'], true)) {
            return false;
        }
        return $safe;
    }

    /** A product counts when its own id, or the id of its product group, is listed. */
    public function countsProduct(int $productId, int $groupId): bool
    {
        return in_array($productId, $this->productIds, true)
            || in_array($groupId, $this->productGroupIds, true);
    }

    public function countsAddon(int $addonId): bool
    {
        return in_array($addonId, $this->addonIds, true);
    }

    public function countsStatus(string $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    public function description(string $percent, int $count): string
    {
        return self::renderDescription($this->descriptionTemplate, $percent, $count);
    }

    /** One substitution shared by the invoice line and the Settings tab example, so the example shows exactly what an invoice gets. */
    public static function renderDescription(string $template, string $percent, int $count): string
    {
        if ($template === self::OLD_DEFAULT_DESCRIPTION) {
            // The 0.2.0 default survives a 0.2.1 upgrade when the Settings form was filled before the upgrade ran and then saved.
            $template = self::DEFAULT_DESCRIPTION;
        }
        return str_replace(['{percent}', '{count}', '{items}'], [$percent, (string) $count, $count === 1 ? 'item' : 'items'], $template);
    }

    /** @return list<string> */
    private static function split(string $value): array
    {
        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * WHMCS ids are positive integers well under ten digits; the Settings form accepts the same, so
     * what the engine reads and what the form shows never differ (an id the engine took but the form
     * dropped would vanish on the next save without a word).
     *
     * @param list<string> $problems
     * @return list<int>
     */
    private static function ids(string $key, string $value, array &$problems): array
    {
        $ids = [];
        foreach (self::split($value) as $item) {
            if (preg_match('/^\d{1,9}$/', $item) !== 1) {
                $problems[] = $key . ': "' . $item . '" is not a number';
                continue;
            }
            if ((int) $item === 0) {
                $problems[] = $key . ': "' . $item . '" is not a WHMCS id (ids start at 1)';
                continue;
            }
            $ids[] = (int) $item;
        }
        return array_values(array_unique($ids));
    }
}
