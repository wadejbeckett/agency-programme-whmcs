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

namespace WHMCS\Authentication {
    /** "Authentication" in the WHMCS developer docs: the authenticated client-area user and client. */
    class CurrentUser
    {
        public function user(): ?\WHMCS\User\User { return null; }
        public function client(): ?\WHMCS\User\Client { return null; }
        public function isAuthenticatedUser(): bool { return false; }
        public function isAuthenticatedAdmin(): bool { return false; }
        public function isMasqueradingAdmin(): bool { return false; }
    }
}

namespace WHMCS\User {
    /**
     * Eloquent model of a client account. Its hasPermission() is left out on purpose: on WHMCS 9.1 it
     * throws "not implemented" (#62), so a call to it should fail the analysis.
     * @property int $id
     */
    class Client
    {
        public function __get(string $name): mixed { return null; }
        public function isOwnedBy(User $user): bool { return false; }
    }
    /**
     * Eloquent model of a client-area user.
     * @property int $id
     */
    class User
    {
        public function __get(string $name): mixed { return null; }
        /** @return array<int, mixed> the clients (rows of client data on WHMCS 9.1) the user holds the permission on; the owner holds every permission */
        public function getClientsByPermission(string $permission): array { return []; }
        public function isOwner(Client $client): bool { return false; }
    }
}

namespace WHMCS\Module {
    /** Base class of admin home widgets ("Admin Dashboard Widgets" in the WHMCS developer docs); properties are untyped there. */
    abstract class AbstractWidget
    {
        /** @var string */
        protected $title = '';
        /** @var string */
        protected $description = '';
        /** @var int */
        protected $weight = 100;
        /** @var int */
        protected $columns = 1;
        /** @var bool */
        protected $cache = false;
        /** @var int */
        protected $cacheExpiry = 120;
        /** @var string */
        protected $requiredPermission = '';

        abstract public function getData(): mixed;
        abstract public function generateOutput(mixed $data): mixed;
    }
}

namespace WHMCS\View\Menu {
    /** A client-area menu or panel tree node ("Client Area Navigation Menus / Homepage Panels" in the WHMCS developer docs). */
    class Item
    {
        /** @param array<string, mixed> $attributes */
        public function addChild(string $name, array $attributes = []): self { return $this; }
        public function getChild(string $name): ?self { return null; }
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
