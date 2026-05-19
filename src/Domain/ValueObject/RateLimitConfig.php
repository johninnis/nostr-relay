<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;

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

    public function perMinute(RateLimitMetric $metric): int
    {
        return match ($metric) {
            RateLimitMetric::Events => $this->eventsPerMinute,
            RateLimitMetric::Subscriptions => $this->subscriptionsPerMinute,
        };
    }
}
