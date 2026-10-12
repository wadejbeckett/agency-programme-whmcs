<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

final class Tier
{
    public function __construct(
        public readonly int $index,
        public readonly string $above,
        public readonly string $percent,
    ) {
    }
}
