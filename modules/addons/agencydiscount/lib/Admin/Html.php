<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Admin;

/** HTML output helpers. Every dynamic value on an admin page passes through e(). */
final class Html
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A module URL with extra query parameters, ready to drop into an href attribute.
     *
     * @param array<string, string|int|null> $query null values are left out
     */
    public static function link(string $moduleLink, array $query): string
    {
        return self::e($moduleLink . '&' . http_build_query($query));
    }

    /**
     * e.g. "ABC 1,012.92": the decimal string is formatted by hand, never through a float.
     * Amounts are rounded to two places by bcadd, which truncates; callers pass two-place values.
     */
    public static function money(string $currency, string $amount): string
    {
        $value = bcadd($amount, '0', 2);
        $sign = '';
        if (str_starts_with($value, '-')) {
            $sign = '-';
            $value = substr($value, 1);
        }
        [$whole, $fraction] = explode('.', $value) + [1 => '00'];
        $grouped = strrev((string) preg_replace('/(\d{3})(?=\d)/', '$1,', strrev($whole)));
        return trim($currency . ' ' . $sign . $grouped . '.' . $fraction);
    }
}
