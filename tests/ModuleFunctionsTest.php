<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Config;
use AgencyDiscount\FrozenClock;
use AgencyDiscount\Hooks;
use AgencyDiscount\Module;
use AgencyDiscount\RecordingActivityLog;
use AgencyDiscount\Tests\Support\Fixtures;
use AgencyDiscount\Tests\Support\WhmcsCalls;
use PHPUnit\Framework\TestCase;

/** The module's WHMCS entry points that need no database: the Configure form definition. */
final class ModuleFunctionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/agencydiscount/agencydiscount.php';
    }

    public function testTheModulePageIsDeniedToAUserWithoutTheInvoicesPermissionAndFailsClosed(): void
    {
        Hooks::reset();
        WhmcsCalls::reset();
        $repo = Fixtures::agencyRepo();
        $repo->saveAgency(['clientId' => 131, 'approved' => true, 'approvedBy' => 'admin:1', 'approvedAt' => '2026-10-01 00:00:00', 'updatedAt' => '2026-10-01 00:00:00']);
        Hooks::$factory = static fn (): Module => new Module($repo, Config::fromSettings(Fixtures::settings()), new FrozenClock(new \DateTimeImmutable('2026-10-12 09:00:00')), new RecordingActivityLog());
        \WHMCS\Session::$values['uid'] = 131;
        try {
            // an invited user with the profile and tickets permissions only
            \WHMCS\Authentication\CurrentUser::$owner = false;
            \WHMCS\Authentication\CurrentUser::$permissions = ['profile', 'tickets'];
            $page = \agencydiscount_clientarea([]);
            self::assertSame(['clientarea', true], [$page['templatefile'], $page['requirelogin']]);
            self::assertSame(['approved', 'permitted', 'unavailable', 'text'], array_keys($page['vars']));
            self::assertSame([false, false, false], [$page['vars']['approved'], $page['vars']['permitted'], $page['vars']['unavailable']]);
            self::assertSame([], WhmcsCalls::$activity, 'a denied user is not a failure');

            // the same user with the invoices permission
            \WHMCS\Authentication\CurrentUser::$permissions = ['profile', 'tickets', 'invoices'];
            $page = \agencydiscount_clientarea([]);
            self::assertSame([true, true], [$page['vars']['approved'], $page['vars']['permitted']]);
            self::assertArrayHasKey('spend', $page['vars']);

            // the owner, whose link carries no permission list
            \WHMCS\Authentication\CurrentUser::$owner = true;
            \WHMCS\Authentication\CurrentUser::$permissions = [];
            self::assertTrue(\agencydiscount_clientarea([])['vars']['approved']);

            // the module cannot be built: the page says so instead of calling the client an outsider, and the error is logged
            Hooks::reset();
            Hooks::$factory = static function (): Module {
                throw new \RuntimeException('boom');
            };
            $page = \agencydiscount_clientarea([]);
            self::assertSame(['approved' => false, 'permitted' => true, 'unavailable' => true], array_intersect_key($page['vars'], ['approved' => 1, 'permitted' => 1, 'unavailable' => 1]));
            self::assertSame('Agency Discount: client area: RuntimeException: boom', WhmcsCalls::$activity[0]['message'] ?? null);
        } finally {
            Hooks::reset();
            WhmcsCalls::reset();
        }
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
