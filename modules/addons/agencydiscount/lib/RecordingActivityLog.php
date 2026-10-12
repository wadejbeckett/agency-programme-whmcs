<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** Keeps entries in memory for tests. */
final class RecordingActivityLog implements ActivityLog
{
    /** @var list<array{message:string, clientId:int}> */
    public array $entries = [];

    public function log(string $message, int $clientId = 0): void
    {
        $this->entries[] = ['message' => $message, 'clientId' => $clientId];
    }
}
