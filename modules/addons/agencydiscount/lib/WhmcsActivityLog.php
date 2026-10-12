<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** Production binding: WHMCS defines logActivity() at runtime. */
final class WhmcsActivityLog implements ActivityLog
{
    public function log(string $message, int $clientId = 0): void
    {
        \logActivity($message, $clientId);
    }
}
