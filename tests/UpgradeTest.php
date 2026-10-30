<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\ArrayRepository;
use AgencyDiscount\Config;
use AgencyDiscount\Module;
use AgencyDiscount\Schema;
use AgencyDiscount\Upgrade;
use PHPUnit\Framework\TestCase;

final class UpgradeTest extends TestCase
{
    public function testFrom020ReshapesTheInvoicesTableAndReplacesTheOldDefaultText(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => 'Agency discount {percent}% on {count} qualifying items', 'keep_data' => '']]);
        $changes = Upgrade::run($repo, '0.2.0');
        self::assertSame(Schema::upgradeStatements('0.2.0'), $repo->executed);
        self::assertSame('Agency discount {percent}% on {count} qualifying {items}', $repo->settings['description']);
        self::assertSame('', $repo->settings['keep_data'], 'the retired keep_data row is left alone');
        self::assertCount(2, $changes);
        self::assertStringContainsString('one row per discount line', $changes[0]);
        self::assertStringContainsString('invoice line text changed', $changes[1]);
    }

    public function testACustomisedTextIsKept(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => 'Partner rebate {percent}% ({count} items)']]);
        $changes = Upgrade::run($repo, '0.2.0');
        self::assertSame('Partner rebate {percent}% ({count} items)', $repo->settings['description']);
        self::assertCount(1, $changes);
    }

    public function testNoStoredTextIsLeftToTheDefault(): void
    {
        $repo = new ArrayRepository();
        Upgrade::run($repo, '0.1.0');
        self::assertSame([], $repo->settings);
        self::assertSame(Config::DEFAULT_DESCRIPTION, Config::defaults()['description']);
    }

    public function testFromTheCurrentVersionNothingChanges(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => Config::OLD_DEFAULT_DESCRIPTION]]);
        self::assertSame([], Upgrade::run($repo, Module::VERSION));
        self::assertSame([], $repo->executed);
        self::assertSame(Config::OLD_DEFAULT_DESCRIPTION, $repo->settings['description']);
    }

    public function testFrom021RunsNoStatementAndChangesNoSetting(): void
    {
        $settings = ['description' => 'Custom {percent}% text', 'tiers' => '{"USD":[{"above":0,"percent":5}]}', 'dry_run' => ''];
        $repo = new ArrayRepository(['settings' => $settings]);
        self::assertSame([], Schema::upgradeStatements('0.2.1'));
        self::assertSame([], Upgrade::run($repo, '0.2.1'));
        self::assertSame([], $repo->executed);
        self::assertSame($settings, $repo->settings);
    }

    public function testAnUnknownVersionChangesNothing(): void
    {
        $repo = new ArrayRepository(['settings' => ['description' => Config::OLD_DEFAULT_DESCRIPTION]]);
        try {
            Upgrade::run($repo, 'unknown');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"unknown" is not a version number', $e->getMessage());
        }
        self::assertSame([], $repo->executed);
        self::assertSame(Config::OLD_DEFAULT_DESCRIPTION, $repo->settings['description']);
    }
}
