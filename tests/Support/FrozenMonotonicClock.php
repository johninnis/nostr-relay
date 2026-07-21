<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Relay\Application\Port\MonotonicClockInterface;
use Override;

final class FrozenMonotonicClock implements MonotonicClockInterface
{
    public function __construct(private float $now = 0.0)
    {
    }

    #[Override]
    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
