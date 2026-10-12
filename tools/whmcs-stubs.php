<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

/** Minimal WHMCS symbol stubs for PHPStan. Never deployed or executed. */

namespace Illuminate\Database\Capsule {
    class Manager
    {
        public static function table(string $table): mixed { return null; }
        public static function schema(): mixed { return null; }
        public static function connection(?string $name = null): mixed { return null; }
    }
}

namespace WHMCS\Database {
    /** WHMCS's documented database entry point ("Interacting with the Database"); wraps Illuminate's Capsule Manager. */
    class Capsule
    {
        public static function table(string $table): mixed { return null; }
        public static function schema(): mixed { return null; }
        public static function connection(?string $name = null): mixed { return null; }
    }
}

namespace WHMCS {
    class Session
    {
        public static function get(string $key): mixed { return null; }
    }
}

namespace {
    function add_hook(string $hookPoint, int $priority, callable $function): void {}
    function logActivity(string $description, int $userId = 0): void {}
    /** @param array<string, mixed> $values @return array<string, mixed> */
    function localAPI(string $command, array $values = [], string $adminUser = ''): array { return []; }
    /** @param string $outputType 'form' (hidden input), 'link' or 'plain' */
    function generate_token(string $outputType = 'form'): string { return ''; }
    function check_token(string $namespace = 'WHMCS.admin.default'): void {}
}
