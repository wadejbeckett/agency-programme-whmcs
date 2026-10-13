<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount\Tests\Support {
    final class WhmcsCalls
    {
        /** @var list<array{hook: string, priority: int, callable: callable}> */
        public static array $hooks = [];
        /** @var list<array{message: string, clientId: int}> */
        public static array $activity = [];

        public static function reset(): void
        {
            self::$hooks = [];
            self::$activity = [];
            \WHMCS\Session::$values = [];
        }
    }
}

namespace WHMCS {
    if (!class_exists('WHMCS\Session', false)) {
        /** Stands in for the WHMCS session: tests set $values ('adminid', 'uid'). */
        final class Session
        {
            /** @var array<string, mixed> */
            public static array $values = [];

            public static function get(string $key): mixed
            {
                return self::$values[$key] ?? null;
            }
        }
    }
}

namespace {
    use AgencyDiscount\Tests\Support\WhmcsCalls;

    if (!function_exists('add_hook')) {
        function add_hook(string $hookPoint, int $priority, callable $function): void
        {
            WhmcsCalls::$hooks[] = ['hook' => $hookPoint, 'priority' => $priority, 'callable' => $function];
        }
    }
    if (!function_exists('logActivity')) {
        function logActivity(string $description, int $userId = 0): void
        {
            WhmcsCalls::$activity[] = ['message' => $description, 'clientId' => $userId];
        }
    }
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
}
