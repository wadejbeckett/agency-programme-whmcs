<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AgencyDiscount;

final class ConfigException extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(private readonly array $problems)
    {
        parent::__construct('Agency Programme configuration problems: ' . implode('; ', $problems));
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }
}
