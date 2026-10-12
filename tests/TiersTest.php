<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ConfigException;
use AgencyDiscount\Tiers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TiersTest extends TestCase
{
    private const JSON = '{"ZAR":[{"above":5000,"percent":5},{"above":"10000","percent":"10"},{"above":15000,"percent":15},{"above":20000,"percent":20}],"EUR":[{"above":250,"percent":5}]}';

    public function testHighestQualifyingTierIsChosenStrictlyAbove(): void
    {
        $tiers = Tiers::fromJson(self::JSON);
        self::assertNull($tiers->tierFor('ZAR', '5000.00'));
        self::assertSame(1, $tiers->tierFor('ZAR', '5000.01')?->index);
        self::assertSame('5.00', $tiers->tierFor('ZAR', '9885.00')?->percent);
        self::assertSame(2, $tiers->tierFor('ZAR', '10000.01')?->index);
        self::assertSame(4, $tiers->tierFor('ZAR', '99999.00')?->index);
        self::assertSame('20.00', $tiers->tierFor('ZAR', '99999.00')?->percent);
    }

    public function testNextTierAndCurrencies(): void
    {
        $tiers = Tiers::fromJson(self::JSON);
        self::assertSame('10000.00', $tiers->nextTier('ZAR', '9885.00')?->above);
        self::assertNull($tiers->nextTier('ZAR', '99999.00'));
        self::assertSame('5000.00', $tiers->nextTier('ZAR', '0.00')?->above);
        self::assertSame(['ZAR', 'EUR'], $tiers->currencies());
        self::assertTrue($tiers->hasCurrency('EUR'));
        self::assertFalse($tiers->hasCurrency('USD'));
        self::assertNull($tiers->tierFor('USD', '99999.00'));
        self::assertCount(4, $tiers->forCurrency('ZAR'));
        self::assertSame([], $tiers->forCurrency('USD'));
    }

    public function testEmptyObjectIsValid(): void
    {
        $tiers = Tiers::fromJson('{}');
        self::assertSame([], $tiers->currencies());
    }

    /** @return iterable<string, array{string, string}> */
    public static function badJson(): iterable
    {
        yield 'invalid' => ['{', 'tiers: invalid JSON'];
        yield 'list not object' => ['[]', 'tiers: must be a JSON object keyed by currency code'];
        yield 'lowercase' => ['{"zar":[{"above":1,"percent":1}]}', 'tiers: currency "zar" must be a three-letter uppercase code'];
        yield 'empty list' => ['{"ZAR":[]}', 'tiers: ZAR must list at least one tier'];
        yield 'unsorted' => ['{"ZAR":[{"above":10,"percent":1},{"above":5,"percent":2}]}', 'tiers: ZAR tier 2 "above" must be greater than tier 1'];
        yield 'percent zero' => ['{"ZAR":[{"above":10,"percent":0}]}', 'tiers: ZAR tier 1 "percent" must be between 0 and 100'];
        yield 'percent high' => ['{"ZAR":[{"above":10,"percent":101}]}', 'tiers: ZAR tier 1 "percent" must be between 0 and 100'];
        yield 'above missing' => ['{"ZAR":[{"percent":5}]}', 'tiers: ZAR tier 1 "above" must be a number'];
    }

    #[DataProvider('badJson')]
    public function testProblemsAreReported(string $json, string $problem): void
    {
        try {
            Tiers::fromJson($json);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertContains($problem, $e->problems());
        }
    }
}
