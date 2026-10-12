<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $at)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }
}
