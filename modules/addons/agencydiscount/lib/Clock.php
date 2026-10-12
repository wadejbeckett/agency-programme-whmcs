<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

/** Source of the current time, so tests can freeze it. */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
