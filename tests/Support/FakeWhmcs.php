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
            \WHMCS\Authentication\CurrentUser::reset();
        }
    }
}

namespace WHMCS\View\Menu {
    if (!class_exists('WHMCS\View\Menu\Item', false)) {
        /** Stands in for a WHMCS client-area panel tree: records what addChild() was given. */
        final class Item
        {
            /** @var array<string, array<string, mixed>> name => attributes */
            public array $children = [];

            /** @param array<string, mixed> $attributes */
            public function addChild(string $name, array $attributes = []): self
            {
                $this->children[$name] = $attributes;
                return $this;
            }
        }
    }
}

namespace WHMCS\Authentication {
    if (!class_exists('WHMCS\Authentication\CurrentUser', false)) {
        /**
         * Stands in for WHMCS's CurrentUser: by default the authenticated client is the session's
         * 'uid' and the user is its owner; tests set the knobs for an invited user with chosen
         * permissions, an admin logged in as the owner, a client that is not the session's, or
         * nobody logged in.
         */
        final class CurrentUser
        {
            public static ?int $clientId = null;
            public static bool $owner = true;
            /** @var list<string> */
            public static array $permissions = [];
            public static bool $masquerading = false;
            public static bool $loggedOut = false;

            public static function reset(): void
            {
                self::$clientId = null;
                self::$owner = true;
                self::$permissions = [];
                self::$masquerading = false;
                self::$loggedOut = false;
            }

            public function client(): ?\WHMCS\User\Client
            {
                if (self::$loggedOut) {
                    return null;
                }
                $id = self::$clientId ?? (int) (\WHMCS\Session::get('uid') ?? 0);
                return $id > 0 ? new \WHMCS\User\Client($id, self::$owner, self::$permissions) : null;
            }

            public function user(): ?\WHMCS\User\User
            {
                if (self::$loggedOut) {
                    return null;
                }
                return new \WHMCS\User\User(self::$owner ? 1 : 2, self::$owner, self::$permissions, self::$clientId ?? (int) (\WHMCS\Session::get('uid') ?? 0));
            }

            public function isAuthenticatedUser(): bool
            {
                return !self::$loggedOut;
            }

            public function isMasqueradingAdmin(): bool
            {
                return self::$masquerading;
            }
        }
    }
}

namespace WHMCS\User {
    if (!class_exists('WHMCS\User\Client', false)) {
        final class Client
        {
            /** @param list<string> $permissions */
            public function __construct(public int $id, private bool $owner, private array $permissions)
            {
            }

            /** As on WHMCS 9.1, where the method exists but throws for every user (#62): any call fails the tests. */
            public function hasPermission(string $permission): bool
            {
                throw new \RuntimeException('WHMCS\\User\\Client::hasPermission not implemented');
            }

            public function isOwnedBy(User $user): bool
            {
                return $this->owner && $user->id === 1;
            }
        }
    }
    if (!class_exists('WHMCS\User\User', false)) {
        final class User
        {
            /** @param list<string> $permissions the permission list on the user's link to $clientId */
            public function __construct(public int $id, private bool $owner = true, private array $permissions = [], private int $clientId = 0)
            {
            }

            /**
             * As on WHMCS 9.1: a row of client data for each client the user holds the permission
             * on, the owner holding every permission.
             *
             * @return list<array<string, mixed>>
             */
            public function getClientsByPermission(string $permission): array
            {
                if ($this->clientId <= 0 || (!$this->owner && !in_array($permission, $this->permissions, true))) {
                    return [];
                }
                return [['id' => $this->clientId, 'firstname' => 'Fake', 'status' => 'Active']];
            }
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
