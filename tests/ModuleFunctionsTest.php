<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Config;
use AgencyDiscount\Module;
use PHPUnit\Framework\TestCase;

/** The module's WHMCS entry points that need no database: the Configure form definition. */
final class ModuleFunctionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/agencydiscount/agencydiscount.php';
    }

    public function testTheConfigureFormHasExactlyTheHousekeepingFields(): void
    {
        $config = \agencydiscount_config();
        self::assertSame(['name', 'description', 'version', 'author', 'language', 'fields'], array_keys($config));
        self::assertSame(Module::VERSION, $config['version']);
        // the one-home ruling: every other key is edited on the Settings tab only
        self::assertSame(['activity_log', 'log_retention_months', 'delete_data'], array_keys($config['fields']));
        self::assertSame('yes', $config['fields']['activity_log']['Default']);
        self::assertSame(Config::defaults()['log_retention_months'], $config['fields']['log_retention_months']['Default']);
        self::assertArrayNotHasKey('Default', $config['fields']['delete_data'], 'deleting data is never on by default');
        foreach ($config['fields'] as $field) {
            self::assertStringNotContainsString('Settings tab', $field['Description'], 'no field points elsewhere: each setting has one home');
        }
        self::assertStringContainsString('Addons > Agency Programme > Settings', $config['description']);
    }
}
