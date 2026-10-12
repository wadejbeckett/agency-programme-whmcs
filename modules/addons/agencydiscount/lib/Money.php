<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** Decimal-string money arithmetic. Never floats. */
final class Money
{
    private const CYCLE_MONTHS = [
        'Monthly' => 1, 'Quarterly' => 3, 'Semi-Annually' => 6,
        'Annually' => 12, 'Biennially' => 24, 'Triennially' => 36,
    ];

    public static function monthlyEquivalent(string $amount, string $billingCycle): string
    {
        $months = self::CYCLE_MONTHS[$billingCycle] ?? null;
        if ($months === null) {
            return '0.00';
        }
        return self::roundHalfUp(bcdiv($amount, (string) $months, 4));
    }

    public static function roundHalfUp(string $amount, int $scale = 2): string
    {
        if (bccomp($amount, '0', 10) < 0) {
            throw new \InvalidArgumentException('roundHalfUp expects a non-negative amount, got ' . $amount);
        }
        $half = '0.' . str_repeat('0', $scale) . '5';
        return bcadd($amount, $half, $scale);
    }

    public static function sum(string ...$amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }
        return $total;
    }

    public static function percentOf(string $amount, string $percent): string
    {
        return self::roundHalfUp(bcdiv(bcmul($amount, $percent, 6), '100', 6));
    }

    public static function gt(string $a, string $b): bool
    {
        return bccomp($a, $b, 2) === 1;
    }

    public static function isPositive(string $a): bool
    {
        return bccomp($a, '0', 2) === 1;
    }

    public static function negate(string $amount): string
    {
        $normalised = bcadd($amount, '0', 2);
        return bccomp($normalised, '0', 2) === 0 ? '0.00' : bcmul($normalised, '-1', 2);
    }

    public static function formatPercent(string $percent): string
    {
        $value = bcadd($percent, '0', 2);
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
