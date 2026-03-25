<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

interface RateLimiterInterface
{
    public function checkLimit(string $key): void;

    public function reset(string $key): void;
}
