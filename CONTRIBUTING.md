# Contributing

Agency Programme for WHMCS is developed in the open. Bug reports and pull requests are welcome through GitHub issues and pull requests. Security problems go through a private security advisory instead; see [SECURITY.md](SECURITY.md).

## Toolchain

Runtime: PHP 8.1 or newer (WHMCS 9.x; 8.x on PHP 8.1 or newer is untested). The development tooling (PHPUnit, PHPStan, illuminate/database for the Capsule tests) is locked against a PHP 8.1 platform (`config.platform.php` in `composer.json`), so `composer install` works on every supported PHP version with one lock file.

PHPStan analyses the module against PHP 8.1 (`phpVersion: 80100` in `phpstan.neon`), so code under `modules/` and `bin/` must not use syntax or functions newer than 8.1.

## Before sending a change

```bash
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpstan analyse --no-progress
```

Both must pass; CI runs them on PHP 8.1, 8.2 and 8.3. The integration tests in `tests/integration/` run against a MariaDB copy of a WHMCS database and are skipped unless their environment variables are set; see the comments at the top of each test.

The PHPStan stubs in `tools/whmcs-stubs.php` return `mixed` for Capsule, so static analysis does not check how `CapsuleRepository` uses it. The sandbox integration test (`tests/integration/SandboxTest.php`, set up with `tools/sandbox.sh`) is the only guard for `CapsuleRepository`: run it after touching that file.

## Conventions

- Every source file starts with an SPDX header: `// SPDX-License-Identifier: AGPL-3.0-or-later` (`# ...` in shell scripts).
- Documentation and user-facing text are written in the organisation's voice ("the module", "we"), never in the first person singular.
- Nothing under `modules/` may carry an environment-specific value: no host names, currency codes, product ids or client ids. `tests/ProfilesTest.php` enforces part of this; example settings belong in `profiles/`.
- Money is handled as decimal strings with `bcmath`, never as floats.
