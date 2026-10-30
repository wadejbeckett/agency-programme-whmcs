<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Loads the module library and the development-only classes used by the CLI,
 * the tests and the preview harness. WHMCS loads only the module's own
 * lib/Bootstrap.php.
 */

require_once __DIR__ . '/../../modules/addons/agencydiscount/lib/Bootstrap.php';
require_once __DIR__ . '/ArrayRepository.php';
require_once __DIR__ . '/FrozenClock.php';
require_once __DIR__ . '/RecordingActivityLog.php';
require_once __DIR__ . '/SnapshotLoader.php';
require_once __DIR__ . '/Cli.php';
require_once __DIR__ . '/ClientAreaTemplate.php';
