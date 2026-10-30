<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

use AgencyDiscount\Config;
use AgencyDiscount\ConfigException;
use AgencyDiscount\Money;
use AgencyDiscount\Tiers;

/**
 * The Settings tab's form state: tier rows per currency and id lists instead of the JSON and
 * comma-separated strings the module stores. Built from the current Config (a GET), from the
 * stored settings as they are (the repair page, when they do not parse) or from a POST (preview,
 * save, copy), and turned back into the stored setting keys so validation and saving go through
 * Config::fromSettings and the same keys the CLI profile uses. The housekeeping keys (activity
 * log, change log retention, delete data on deactivate) are the WHMCS Configure form's and are
 * not on this form.
 *
 * Values are kept as typed: an invalid threshold stays in its row so the admin sees what was
 * rejected. Nothing here is a float; thresholds are converted with bcmath.
 *
 * @phpstan-type TierRow array{above:string, percent:string}
 */
final class SettingsForm
{
    /** The WHMCS service and add-on statuses offered as checkboxes, in the order shown. */
    public const STATUSES = ['Active', 'Suspended', 'Pending', 'Cancelled', 'Terminated', 'Fraud', 'Completed'];
    /** The yes/no settings on the form; dry_run is the mode card's, not the form's. */
    public const SWITCHES = ['new_orders', 'stack_with_promotions'];
    /** The stored keys this form writes. */
    public const KEYS = ['tiers', 'product_groups', 'products', 'addons', 'statuses', 'new_orders', 'stack_with_promotions', 'description'];
    /**
     * The currency keys given a pane: letters and digits only, so a key is safe in a field name and in
     * the row template's attributes. Any other key (a stored value from SQL or an import, a crafted
     * POST) is left out; the validation problem names it and a save replaces the whole setting.
     */
    private const CODE_PATTERN = '/^[A-Za-z0-9]{1,10}$/';

    /**
     * @param array<string, list<TierRow>> $tiers currency code => rows as typed, ascending as entered
     * @param list<int> $groupIds
     * @param list<int> $productIds
     * @param list<int> $addonIds
     * @param list<string> $statuses
     * @param array<string, bool> $switches one of SWITCHES => on
     * @param string|null $unreadableTiers the stored tiers value when it is not a JSON object at all (fromSettings only), shown so the admin sees what a save replaces
     */
    public function __construct(
        public readonly array $tiers,
        public readonly array $groupIds,
        public readonly array $productIds,
        public readonly array $addonIds,
        public readonly array $statuses,
        public readonly array $switches,
        public readonly string $description,
        public readonly ?string $unreadableTiers = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $tiers = [];
        foreach ($config->tiers->currencies() as $code) {
            foreach ($config->tiers->forCurrency($code) as $tier) {
                $tiers[$code][] = ['above' => $tier->above, 'percent' => Money::formatPercent($tier->percent)];
            }
        }
        return new self(
            $tiers,
            $config->productGroupIds,
            $config->productIds,
            $config->addonIds,
            $config->statuses,
            ['new_orders' => $config->newOrders, 'stack_with_promotions' => $config->stackWithPromotions],
            $config->descriptionTemplate,
        );
    }

    /**
     * The form from the stored settings as they are, for the repair page (the settings do not
     * parse, so there is no Config to build from). The tiers JSON is read as far as it goes: each
     * row's cells as stored, so a bad cell is shown in its row; a value that is not a JSON object
     * gives no rows and is kept in $unreadableTiers. Ids that are not numbers are dropped (the
     * problem list names them), statuses are split on commas, and a missing key takes the
     * module's default, as Config::fromSettings does.
     *
     * @param array<string, mixed> $settings raw addon settings
     */
    public static function fromSettings(array $settings): self
    {
        $s = [];
        foreach (Config::defaults() as $key => $default) {
            $value = $settings[$key] ?? $default;
            $s[$key] = is_scalar($value) ? (string) $value : $default;
        }

        $tiers = [];
        $unreadable = null;
        // Decoded twice, as Tiers::fromJson does: the object form tells a JSON object from a list.
        $decoded = json_decode($s['tiers'], true);
        if (!json_decode($s['tiers']) instanceof \stdClass || !is_array($decoded)) {
            $unreadable = $s['tiers'];
        } else {
            foreach ($decoded as $code => $rows) {
                $code = (string) $code; // PHP turns a key like "123" into an int
                if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                    continue;
                }
                $list = [];
                foreach (self::arr($rows) as $row) {
                    $row = is_array($row) ? $row : ['above' => $row];
                    $list[] = ['above' => self::str($row['above'] ?? null), 'percent' => self::str($row['percent'] ?? null)];
                }
                $tiers[$code] = $list;
            }
        }

        $switches = [];
        foreach (self::SWITCHES as $key) {
            $switches[$key] = Config::yesNo($s[$key], false);
        }

        return new self(
            $tiers,
            self::ids(explode(',', $s['product_groups'])),
            self::ids(explode(',', $s['products'])),
            self::ids(explode(',', $s['addons'])),
            self::statuses(explode(',', $s['statuses'])),
            $switches,
            trim($s['description']),
            $unreadable,
        );
    }

    /**
     * Field names: tiers[CODE][n][above], tiers[CODE][n][percent] (any n; a row with both blank is
     * dropped), product_groups[], products[], addons[], statuses[], the SWITCHES as "on" when ticked,
     * and description. Anything that is not a string where one is expected is ignored.
     *
     * @param array<string, mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $tiers = [];
        foreach (self::arr($post['tiers'] ?? null) as $code => $rows) {
            $code = (string) $code;
            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                continue;
            }
            $list = [];
            foreach (self::arr($rows) as $row) {
                $row = self::arr($row);
                $above = trim(self::str($row['above'] ?? null));
                $percent = trim(self::str($row['percent'] ?? null));
                if ($above === '' && $percent === '') {
                    continue;
                }
                $list[] = ['above' => $above, 'percent' => $percent];
            }
            $tiers[$code] = $list;
        }

        $switches = [];
        foreach (self::SWITCHES as $key) {
            $switches[$key] = Config::yesNo($post[$key] ?? '', false);
        }

        return new self(
            $tiers,
            self::ids($post['product_groups'] ?? null),
            self::ids($post['products'] ?? null),
            self::ids($post['addons'] ?? null),
            self::statuses($post['statuses'] ?? null),
            $switches,
            trim(self::str($post['description'] ?? null)),
        );
    }

    /**
     * The same form with one currency's rows replaced.
     *
     * @param list<TierRow> $rows
     */
    public function withTiers(string $code, array $rows): self
    {
        $tiers = $this->tiers;
        $tiers[$code] = $rows;
        return new self($tiers, $this->groupIds, $this->productIds, $this->addonIds, $this->statuses, $this->switches, $this->description, $this->unreadableTiers);
    }

    /**
     * The stored setting keys (KEYS) this form represents. Tier rows become the tiers JSON with
     * numbers where a row holds a number and strings otherwise, so Tiers::fromJson reports the bad
     * row by its position. A currency with no rows is left out (no tiers for that currency).
     *
     * @return array<string, string>
     */
    public function toSettings(): array
    {
        $settings = [
            'tiers' => self::tiersJson($this->tiers),
            'product_groups' => implode(',', $this->groupIds),
            'products' => implode(',', $this->productIds),
            'addons' => implode(',', $this->addonIds),
            'statuses' => implode(',', $this->statuses),
        ];
        foreach (self::SWITCHES as $key) {
            $settings[$key] = ($this->switches[$key] ?? false) ? 'on' : '';
        }
        $settings['description'] = $this->description;
        return $settings;
    }

    /**
     * Validates the form as the module would read it; dry_run is the mode card's and is passed in,
     * and the stored settings supply the housekeeping keys (the Configure form's), so the Config a
     * save or a mode switch runs the rest of the request on carries the stored activity-log and
     * delete-data flags.
     *
     * @param array<string, mixed> $stored the stored settings
     * @throws ConfigException listing every problem
     */
    public function validate(bool $dryRun, array $stored = []): Config
    {
        return Config::fromSettings($this->toSettings() + ['dry_run' => $dryRun ? 'on' : ''] + $stored);
    }

    /**
     * The rows of one currency converted to another at a WHMCS exchange rate (target amount =
     * default-currency amount x rate), thresholds rounded half-up to two places, percentages kept.
     *
     * @param list<TierRow> $rows the source currency's rows
     * @param string $rate the target currency's rate against the default currency, a decimal string
     * @return list<TierRow>
     * @throws ConfigException when the source rows are not a valid tier table
     */
    public static function convertedTiers(string $sourceCode, array $rows, string $rate): array
    {
        $source = Tiers::fromJson(self::tiersJson([$sourceCode => $rows]));
        $converted = [];
        foreach ($source->forCurrency($sourceCode) as $tier) {
            $converted[] = [
                'above' => Money::roundHalfUp(bcmul($tier->above, $rate, 7)),
                'percent' => Money::formatPercent($tier->percent),
            ];
        }
        return $converted;
    }

    /**
     * The tiers setting's JSON from rows as typed: numbers for numeric cells, JSON strings for
     * anything else (so the parser rejects the row rather than the document); "{}" when empty.
     *
     * @param array<string, list<TierRow>> $tiers
     */
    public static function tiersJson(array $tiers): string
    {
        $parts = [];
        foreach ($tiers as $code => $rows) {
            if ($rows === []) {
                continue;
            }
            $items = [];
            foreach ($rows as $row) {
                $items[] = '{"above":' . self::jsonNumber($row['above']) . ',"percent":' . self::jsonNumber($row['percent']) . '}';
            }
            $parts[] = json_encode((string) $code, JSON_THROW_ON_ERROR) . ':[' . implode(',', $items) . ']';
        }
        return '{' . implode(',', $parts) . '}';
    }

    /**
     * A cell that is a plain decimal becomes a JSON number exactly as typed (so Tiers sees a third
     * decimal and can reject it; nothing is rounded here); anything else becomes a JSON string, which
     * Tiers reports as "must be a number". A leading-zero form such as 007.5 is not a JSON number and
     * goes through as a string, which Tiers::decimal() still reads as 7.50.
     */
    private static function jsonNumber(string $value): string
    {
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1) {
            return $value;
        }
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @return array<array-key, mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> the distinct non-empty statuses among the values, in the order given; a value with a comma cannot be stored and is dropped */
    private static function statuses(mixed $values): array
    {
        $statuses = [];
        foreach (self::arr($values) as $status) {
            $status = trim(self::str($status));
            if ($status !== '' && !str_contains($status, ',') && !in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }
        return $statuses;
    }

    /** @return list<int> the distinct positive integers among the values, in the order given */
    private static function ids(mixed $values): array
    {
        $ids = [];
        foreach (self::arr($values) as $value) {
            $value = trim(self::str($value));
            if (preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value > 0 && !in_array((int) $value, $ids, true)) {
                $ids[] = (int) $value;
            }
        }
        return $ids;
    }
}
