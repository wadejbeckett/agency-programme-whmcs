<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\CapsuleRepository;
use AgencyDiscount\Config;
use PHPUnit\Framework\TestCase;

/** The pure parts of CapsuleRepository; the database parts are covered by tests/integration/SandboxTest.php. */
final class CapsuleRepositoryTest extends TestCase
{
    public function testEncodedTiersDecodeIntoATier(): void
    {
        $settings = CapsuleRepository::decodeSettings(['tiers' => '{&quot;ZAR&quot;:[{&quot;above&quot;:1,&quot;percent&quot;:5}]}']);
        self::assertSame('{"ZAR":[{"above":1,"percent":5}]}', $settings['tiers']);
        $tier = Config::fromSettings($settings)->tiers->forCurrency('ZAR')[0];
        self::assertSame('1.00', $tier->above);
        self::assertSame('5.00', $tier->percent);
    }

    public function testPlainValuesAreUnchanged(): void
    {
        $raw = [
            'tiers' => '{"ZAR":[{"above":1,"percent":5}]}',
            'description' => 'Agency discount {percent}% on {count} qualifying items',
            'dry_run' => 'on',
            'product_groups' => '1,23',
        ];
        self::assertSame($raw, CapsuleRepository::decodeSettings($raw));
        self::assertSame(['description' => 'A & B', 'n' => '5', 'null' => ''], CapsuleRepository::decodeSettings(['description' => 'A &amp; B', 'n' => 5, 'null' => null]));
    }

    public function testActorIsCutToSixtyFourCharactersOnACharacterBoundary(): void
    {
        self::assertSame('admin:1', CapsuleRepository::truncate('admin:1'));
        self::assertSame(str_repeat('a', 64), CapsuleRepository::truncate(str_repeat('a', 100)));
        $cut = CapsuleRepository::truncate(str_repeat('é', 70));
        self::assertSame(str_repeat('é', 64), $cut);
        self::assertSame(1, preg_match('//u', $cut));
        self::assertSame(64, strlen(CapsuleRepository::truncate(str_repeat("\xff", 70))));
    }
}
