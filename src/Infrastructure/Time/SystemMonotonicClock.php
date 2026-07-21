<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Time;

use Innis\Nostr\Relay\Application\Port\MonotonicClockInterface;
use Override;

final readonly class SystemMonotonicClock implements MonotonicClockInterface
{
    #[Override]
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
