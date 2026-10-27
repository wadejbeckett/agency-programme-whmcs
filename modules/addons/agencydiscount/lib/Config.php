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
    ];

    /**
     * @param list<int> $productGroupIds
     * @param list<int> $productIds
     * @param list<int> $addonIds
     * @param list<string> $statuses
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
    ) {
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
        );
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
     * @param list<string> $problems
     * @return list<int>
     */
    private static function ids(string $key, string $value, array &$problems): array
    {
        $ids = [];
        foreach (self::split($value) as $item) {
            if (preg_match('/^\d+$/', $item) !== 1) {
                $problems[] = $key . ': "' . $item . '" is not a number';
                continue;
            }
            $ids[] = (int) $item;
        }
        return array_values(array_unique($ids));
    }
}
