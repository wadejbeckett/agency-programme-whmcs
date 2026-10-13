<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Config;
use PHPUnit\Framework\TestCase;

final class ProfilesTest extends TestCase
{
    public function testEveryProfileParsesAndUsesOnlyKnownKeys(): void
    {
        $profiles = glob(__DIR__ . '/../profiles/*.json') ?: [];
        self::assertNotSame([], $profiles, 'at least one example profile must ship');
        foreach ($profiles as $file) {
            $settings = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($settings);
            self::assertSame([], array_diff(array_keys($settings), array_keys(Config::defaults())), basename($file) . ' has unknown keys');
            $config = Config::fromSettings($settings);
            self::assertTrue($config->dryRun, basename($file) . ' must ship with dry-run on');
            self::assertNotSame([], $config->tiers->currencies(), basename($file) . ' must define tiers');
        }
    }

    public function testNoEnvironmentValuesInModuleCode(): void
    {
        $files = [];
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../modules/addons/agencydiscount', \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $file) {
            if ($file instanceof \SplFileInfo && in_array($file->getExtension(), ['php', 'tpl', 'json'], true)) {
                $files[] = $file->getPathname();
            }
        }
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            // "tr[i]nity" so this guard does not itself contain the host name it guards against
            self::assertDoesNotMatchRegularExpression('/noiz\.(co\.za|ie)|tr[i]nity|"ZAR"|\'ZAR\'/', $code, basename($file));
        }
    }

    /** Profiles are generic examples: no file named after, or describing, one installation. */
    public function testProfilesAreNotTiedToOneInstallation(): void
    {
        $files = glob(__DIR__ . '/../profiles/*') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/no[i]z/i', basename($file) . "\n" . (string) file_get_contents($file), basename($file));
        }
    }
}
