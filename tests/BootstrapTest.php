<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests;

use AgencyDiscount\Tests\Support\WhmcsCalls;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testFakeWhmcsRecordsHooksAndActivity(): void
    {
        WhmcsCalls::reset();
        add_hook('InvoiceCreation', 1, static fn () => null);
        logActivity('hello', 7);
        self::assertSame('InvoiceCreation', WhmcsCalls::$hooks[0]['hook']);
        self::assertSame(['message' => 'hello', 'clientId' => 7], WhmcsCalls::$activity[0]);
    }

    public function testWhmcsJsonIsValid(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../modules/addons/agencydiscount/whmcs.json'), true);
        self::assertSame('agencydiscount', $json['name']);
        self::assertSame('AGPL-3.0-or-later', $json['license']);
    }

    /** What WHMCS loads: every library file, and none of the development-only classes in bin/lib/. */
    public function testModuleBootstrapLoadsNoDevelopmentClass(): void
    {
        $lib = realpath(__DIR__ . '/../modules/addons/agencydiscount/lib');
        self::assertIsString($lib);
        $script = 'require ' . var_export($lib . '/Bootstrap.php', true) . ';'
            . ' echo json_encode(array_map(static fn ($c) => class_exists($c, false) || interface_exists($c, false),'
            . ' ["AgencyDiscount\\ArrayRepository", "AgencyDiscount\\FrozenClock", "AgencyDiscount\\RecordingActivityLog", "AgencyDiscount\\SnapshotLoader", "AgencyDiscount\\Cli", "AgencyDiscount\\Repository", "AgencyDiscount\\CapsuleRepository"]));'
            . ' echo "\n", json_encode(array_values(get_included_files()));';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        self::assertIsString($output);
        [$classes, $files] = explode("\n", trim($output), 2) + [1 => '[]'];
        self::assertSame([false, false, false, false, false, true, true], json_decode($classes, true), $output);
        $loaded = array_map(static fn (string $f): string => substr($f, strlen($lib) + 1), (array) json_decode($files, true));
        $expected = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $expected[] = substr((string) $file, strlen($lib) + 1);
        }
        // HomeWidget needs WHMCS's AbstractWidget and is loaded only inside WHMCS
        $expected = array_values(array_diff($expected, ['Admin/HomeWidget.php']));
        sort($expected);
        sort($loaded);
        self::assertSame($expected, $loaded, 'every library file is loaded and nothing else');
    }
}
