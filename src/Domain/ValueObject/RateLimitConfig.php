<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use InvalidArgumentException;

final readonly class RateLimitConfig
{
    public function __construct(
        private int $eventsPerMinute,
        private int $subscriptionsPerMinute,
    ) {
        if ($eventsPerMinute <= 0) {
            throw new InvalidArgumentException('eventsPerMinute must be a positive integer');
        }

        if ($subscriptionsPerMinute <= 0) {
            throw new InvalidArgumentException('subscriptionsPerMinute must be a positive integer');
        }
    }

    public static function fromArray(array $data): ?self
    {
        $events = $data['events_per_minute'] ?? null;
        $subscriptions = $data['subscriptions_per_minute'] ?? null;

        if (!is_numeric($events) || !is_numeric($subscriptions)) {
            return null;
        }

        if ((int) $events <= 0 || (int) $subscriptions <= 0) {
            return null;
        }

        return new self((int) $events, (int) $subscriptions);
    }

    /**
     * @return array{events_per_minute: int, subscriptions_per_minute: int}
     */
    public function toArray(): array
    {
        return [
            'events_per_minute' => $this->eventsPerMinute,
            'subscriptions_per_minute' => $this->subscriptionsPerMinute,
        ];
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
