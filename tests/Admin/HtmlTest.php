<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Admin;

use AgencyDiscount\Admin\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testMoneyGroupsThousandsAndKeepsTwoPlaces(): void
    {
        self::assertSame('ZAR 1,012.92', Html::money('ZAR', '1012.92'));
        self::assertSame('EUR 0.00', Html::money('EUR', '0'));
        self::assertSame('EUR 12,345,678.90', Html::money('EUR', '12345678.9'));
        self::assertSame('USD -1,234.50', Html::money('USD', '-1234.50'));
        self::assertSame('USD 999.00', Html::money('USD', '999.00'));
    }

    public function testLinkEscapesAndSkipsNulls(): void
    {
        self::assertSame('m?x=1&amp;action=log&amp;page=2', Html::link('m?x=1', ['action' => 'log', 'client' => null, 'page' => 2]));
        self::assertSame('m&amp;a=%22%3E%3Cb%3E', Html::link('m', ['a' => '"><b>']));
    }
}
