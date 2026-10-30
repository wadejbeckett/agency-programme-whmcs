<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** Per-currency discount tier tables, parsed from the `tiers` setting. */
final class Tiers
{
    /** @param array<string, list<Tier>> $byCurrency tiers per currency, ascending by "above" */
    private function __construct(private readonly array $byCurrency)
    {
    }

    /** @throws ConfigException */
    public static function fromJson(string $json): self
    {
        $problems = [];
        $byCurrency = [];

        // Decoded twice: the object form tells `{}` (valid, empty) from `[]` (invalid).
        $asObject = json_decode($json);
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ConfigException(['tiers: invalid JSON']);
        }
        if (!$asObject instanceof \stdClass || !is_array($data)) {
            throw new ConfigException(['tiers: must be a JSON object keyed by currency code']);
        }

        foreach ($data as $rawCode => $rawTiers) {
            $code = (string) $rawCode;
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                $problems[] = 'tiers: currency "' . $code . '" must be a three-letter uppercase code';
                continue;
            }
            if (!is_array($rawTiers) || $rawTiers === []) {
                $problems[] = 'tiers: ' . $code . ' must list at least one tier';
                continue;
            }
            $tiers = self::parseCurrency($code, array_values($rawTiers), $problems);
            if ($tiers !== null) {
                $byCurrency[$code] = $tiers;
            }
        }

        if ($problems !== []) {
            throw new ConfigException($problems);
        }
        return new self($byCurrency);
    }

    /**
     * The canonical form of the `tiers` setting: currencies in their order, each tier as
     * {"above": n, "percent": n} with trailing zeros dropped; "{}" when there are no tiers.
     */
    public function toJson(): string
    {
        $parts = [];
        foreach ($this->byCurrency as $code => $tiers) {
            $items = [];
            foreach ($tiers as $tier) {
                $items[] = '{"above":' . Money::formatPercent($tier->above) . ',"percent":' . Money::formatPercent($tier->percent) . '}';
            }
            $parts[] = json_encode($code, JSON_THROW_ON_ERROR) . ':[' . implode(',', $items) . ']';
        }
        return '{' . implode(',', $parts) . '}';
    }

    public function hasCurrency(string $code): bool
    {
        return isset($this->byCurrency[$code]);
    }

    /** The highest tier whose threshold the monthly spend is strictly above. */
    public function tierFor(string $currency, string $monthlySpend): ?Tier
    {
        $found = null;
        foreach ($this->forCurrency($currency) as $tier) {
            if (Money::gt($monthlySpend, $tier->above)) {
                $found = $tier;
            }
        }
        return $found;
    }

    /** The first tier the monthly spend has not yet exceeded. */
    public function nextTier(string $currency, string $monthlySpend): ?Tier
    {
        foreach ($this->forCurrency($currency) as $tier) {
            if (!Money::gt($monthlySpend, $tier->above)) {
                return $tier;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_keys($this->byCurrency);
    }

    /** @return list<Tier> */
    public function forCurrency(string $code): array
    {
        return $this->byCurrency[$code] ?? [];
    }

    /**
     * @param list<mixed> $rawTiers
     * @param list<string> $problems
     * @return list<Tier>|null null when this currency has any problem
     */
    private static function parseCurrency(string $code, array $rawTiers, array &$problems): ?array
    {
        $before = count($problems);
        $tiers = [];
        $previousAbove = null;

        foreach ($rawTiers as $i => $raw) {
            $n = $i + 1;
            $label = 'tiers: ' . $code . ' tier ' . $n;
            if (!is_array($raw)) {
                $problems[] = $label . ' must be an object with "above" and "percent"';
                $previousAbove = null;
                continue;
            }

            $above = self::decimal($raw['above'] ?? null);
            if ($above === null) {
                $problems[] = $label . ' "above" must be a number';
            } elseif (self::tooPrecise($raw['above'] ?? null)) {
                $problems[] = $label . ' "above" must have at most two decimals (got ' . self::shown($raw['above'] ?? null) . ')';
                $above = null;
            } elseif (bccomp($above, '0', 2) < 0) {
                $problems[] = $label . ' "above" must not be negative';
                $above = null;
            } elseif ($previousAbove !== null && !Money::gt($above, $previousAbove)) {
                $problems[] = $label . ' "above" must be greater than tier ' . ($n - 1);
            }

            $percent = self::decimal($raw['percent'] ?? null);
            if ($percent === null) {
                $problems[] = $label . ' "percent" must be a number';
            } elseif (self::tooPrecise($raw['percent'] ?? null)) {
                $problems[] = $label . ' "percent" must have at most two decimals (got ' . self::shown($raw['percent'] ?? null) . ')';
                $percent = null;
            } elseif (bccomp($percent, '0', 2) <= 0 || bccomp($percent, '100', 2) > 0) {
                $problems[] = $label . ' "percent" must be between 0 and 100';
            }

            $previousAbove = $above;
            if ($above !== null && $percent !== null) {
                $tiers[] = new Tier($n, $above, $percent);
            }
        }

        return count($problems) === $before ? $tiers : null;
    }

    /**
     * A number (as decimal() accepts it) with more than two decimals: 1000.009 or 7.555 would be
     * truncated by the two-place normalisation, so it is rejected instead of silently changed.
     */
    private static function tooPrecise(mixed $value): bool
    {
        $text = is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (is_scalar($value) ? trim((string) $value) : '');
        return preg_match('/\.\d{3,}$/', $text) === 1;
    }

    /** The value as the administrator typed or stored it, for a problem message. */
    private static function shown(mixed $value): string
    {
        return is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (is_scalar($value) ? trim((string) $value) : '');
    }

    /** Normalises a JSON number or numeric string to two decimals, or null when it is not a plain number. */
    private static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', 2);
        }
        if (is_float($value)) {
            return is_finite($value) ? bcadd(sprintf('%.4F', $value), '0', 2) : null;
        }
        if (is_string($value) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $m) === 1) {
            return bcadd($m[1], '0', 2);
        }
        return null;
    }
}
