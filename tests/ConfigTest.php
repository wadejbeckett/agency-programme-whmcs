<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Config;
use AgencyDiscount\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $c = Config::fromSettings([]);
        self::assertTrue($c->dryRun);
        self::assertFalse($c->stackWithPromotions);
        self::assertTrue($c->newOrders);
        self::assertTrue($c->activityLog);
        self::assertFalse($c->deleteData, 'deactivating keeps the data unless deletion is asked for');
        self::assertSame(['Active'], $c->statuses);
        self::assertSame([], $c->productGroupIds);
        self::assertSame([], $c->tiers->currencies());
        self::assertSame('Agency discount 10% on 3 qualifying items', $c->description('10', 3));
        self::assertSame('Agency discount 5% on 1 qualifying item', $c->description('5', 1));
        self::assertSame('Agency discount {percent}% on {count} qualifying {items}', Config::defaults()['description']);
    }

    public function testDryRunFailsSafe(): void
    {
        self::assertTrue(Config::fromSettings(['dry_run' => 'yes'])->dryRun);
        self::assertTrue(Config::fromSettings(['dry_run' => 'weird'])->dryRun);
        self::assertTrue(Config::fromSettings(['dry_run' => ' ON '])->dryRun);
        self::assertFalse(Config::fromSettings(['dry_run' => ''])->dryRun);
        self::assertFalse(Config::fromSettings(['dry_run' => 'off'])->dryRun);
        self::assertFalse(Config::fromSettings(['dry_run' => '0'])->dryRun);
        self::assertFalse(Config::fromSettings(['dry_run' => 'No'])->dryRun);
    }

    public function testUnknownYesNoValuesTakeTheSafeSide(): void
    {
        $c = Config::fromSettings(['stack_with_promotions' => 'weird', 'new_orders' => 'weird', 'delete_data' => 'weird', 'activity_log' => 'weird']);
        self::assertFalse($c->stackWithPromotions);
        self::assertFalse($c->newOrders, 'an unrecognised value never widens the discount');
        self::assertFalse($c->deleteData, 'an unrecognised value never deletes data');
        self::assertTrue($c->activityLog);
        $c = Config::fromSettings(['stack_with_promotions' => 'true', 'new_orders' => 'off', 'delete_data' => 'off', 'activity_log' => '0']);
        self::assertTrue($c->stackWithPromotions);
        self::assertFalse($c->newOrders);
        self::assertFalse($c->deleteData);
        self::assertFalse($c->activityLog);
        self::assertTrue(Config::fromSettings(['delete_data' => 'on'])->deleteData);
        self::assertFalse(Config::fromSettings(['delete_data' => ''])->deleteData, 'an unticked WHMCS yes/no box keeps the data');
        self::assertTrue(Config::fromSettings(['new_orders' => 'on'])->newOrders);
        self::assertFalse(Config::fromSettings(['new_orders' => ''])->newOrders);
    }

    public function testParsesLists(): void
    {
        $c = Config::fromSettings([
            'product_groups' => ' 1, 23,3,6 ,, 1',
            'products' => '',
            'addons' => '12,15',
            'statuses' => 'Active, Suspended',
            'dry_run' => '',
            'stack_with_promotions' => 'on',
            'description' => 'Partner rebate {percent}% ({count} items)',
        ]);
        self::assertSame([1, 23, 3, 6], $c->productGroupIds);
        self::assertSame([], $c->productIds);
        self::assertSame([12, 15], $c->addonIds);
        self::assertSame(['Active', 'Suspended'], $c->statuses);
        self::assertFalse($c->dryRun);
        self::assertTrue($c->stackWithPromotions);
        self::assertTrue($c->countsProduct(999, 23));
        self::assertFalse($c->countsProduct(999, 15));
        self::assertTrue($c->countsAddon(15));
        self::assertFalse($c->countsAddon(16));
        self::assertTrue($c->countsStatus('Suspended'));
        self::assertFalse($c->countsStatus('Terminated'));
        self::assertSame('Partner rebate 5% (2 items)', $c->description('5', 2));
    }

    public function testItemsPlaceholderFollowsTheCount(): void
    {
        self::assertSame('2% off 1 item', Config::renderDescription('{percent}% off {count} {items}', '2', 1));
        self::assertSame('2% off 0 items', Config::renderDescription('{percent}% off {count} {items}', '2', 0));
        self::assertSame('2% off 2 items', Config::renderDescription('{percent}% off {count} {items}', '2', 2));
        self::assertSame('Old text 5% on 1 qualifying items', Config::renderDescription('Old text {percent}% on {count} qualifying items', '5', 1), 'a stored template without {items} renders as before');
        $c = Config::fromSettings(['description' => 'Rebate ({count} {items})']);
        self::assertSame('Rebate (1 item)', $c->description('5', 1));
    }

    public function testTheOldDefaultTextRendersAsTheNewDefault(): void
    {
        self::assertSame('Agency discount 5% on 1 qualifying item', Config::renderDescription(Config::OLD_DEFAULT_DESCRIPTION, '5', 1));
        self::assertSame('Agency discount 5% on 2 qualifying items', Config::fromSettings(['description' => Config::OLD_DEFAULT_DESCRIPTION])->description('5', 2));
    }

    public function testProductIdCountsEvenWhenGroupDoesNot(): void
    {
        $c = Config::fromSettings(['products' => '120']);
        self::assertTrue($c->countsProduct(120, 15));
        self::assertFalse($c->countsProduct(121, 15));
    }

    public function testAllProblemsAreReportedTogether(): void
    {
        try {
            Config::fromSettings(['tiers' => '{', 'product_groups' => '1,abc', 'statuses' => ' , ', 'description' => '  ']);
            self::fail('expected ConfigException');
        } catch (ConfigException $e) {
            self::assertSame([
                'tiers: invalid JSON',
                'product_groups: "abc" is not a number',
                'statuses: at least one status is required',
                'description: must not be empty',
            ], $e->problems());
            self::assertStringContainsString('tiers: invalid JSON', $e->getMessage());
        }
    }
}
