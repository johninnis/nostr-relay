<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

interface MonotonicClockInterface
{
    public function now(): float;
}
