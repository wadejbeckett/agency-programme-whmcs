<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function cycles(): iterable
    {
        yield 'monthly' => ['170.00', 'Monthly', '170.00'];
        yield 'quarterly' => ['300.00', 'Quarterly', '100.00'];
        yield 'semi' => ['600.00', 'Semi-Annually', '100.00'];
        yield 'annual' => ['1870.00', 'Annually', '155.83'];
        yield 'annual rounds half up' => ['2805.00', 'Annually', '233.75'];
        yield 'biennial' => ['2400.00', 'Biennially', '100.00'];
        yield 'triennial' => ['3600.00', 'Triennially', '100.00'];
        yield 'free' => ['0.00', 'Free Account', '0.00'];
        yield 'one time' => ['500.00', 'One Time', '0.00'];
        yield 'unknown' => ['500.00', 'Weekly', '0.00'];
    }

    #[DataProvider('cycles')]
    public function testMonthlyEquivalent(string $amount, string $cycle, string $expected): void
    {
        self::assertSame($expected, Money::monthlyEquivalent($amount, $cycle));
    }

    public function testPercentOfRoundsHalfUp(): void
    {
        self::assertSame('185.17', Money::percentOf('1234.45', '15.00'));
        self::assertSame('0.01', Money::percentOf('0.10', '5.00'));
        self::assertSame('988.50', Money::percentOf('9885.00', '10.00'));
        self::assertSame('0.00', Money::percentOf('0.00', '20.00'));
    }

    public function testRoundHalfUpRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::roundHalfUp('-1.005');
    }

    public function testSumNegateCompare(): void
    {
        self::assertSame('10.50', Money::sum('1.25', '9.25'));
        self::assertSame('0.00', Money::sum());
        self::assertSame('-12.30', Money::negate('12.30'));
        self::assertSame('0.00', Money::negate('0.00'));
        self::assertTrue(Money::gt('5000.01', '5000.00'));
        self::assertFalse(Money::gt('5000.00', '5000.00'));
        self::assertTrue(Money::isPositive('0.01'));
        self::assertFalse(Money::isPositive('0.00'));
        self::assertFalse(Money::isPositive('-3.00'));
    }

    public function testFormatPercent(): void
    {
        self::assertSame('10', Money::formatPercent('10.00'));
        self::assertSame('7.5', Money::formatPercent('7.50'));
        self::assertSame('12.25', Money::formatPercent('12.25'));
    }
}
