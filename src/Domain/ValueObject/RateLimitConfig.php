<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class RateLimitConfig
{
    public function __construct(
        private int $eventsPerMinute,
        private int $subscriptionsPerMinute,
    ) {
    }

    public function getEventsPerMinute(): int
    {
        return $this->eventsPerMinute;
    }

    public function getSubscriptionsPerMinute(): int
    {
        return $this->subscriptionsPerMinute;
    }

    public function getEventsRefillRate(): float
    {
        return $this->eventsPerMinute / 60;
    }

    public function getSubscriptionsRefillRate(): float
    {
        return $this->subscriptionsPerMinute / 60;
    }
}
