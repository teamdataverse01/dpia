<?php

declare(strict_types=1);

namespace App\Support;

/** Source of "now" so services and jobs can be tested against fixed dates. */
class Clock
{
    public function __construct(private ?\DateTimeImmutable $fixed = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->fixed ?? new \DateTimeImmutable('now');
    }

    public function today(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }
}
