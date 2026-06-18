<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\RateLimiting;

use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;

final readonly class StaticRateLimitPolicy implements RateLimitPolicyInterface
{
    public function __construct(
        private RateLimitConfig $limits,
    ) {
    }

    public function limitFor(RateLimitMetric $metric): int
    {
        return $this->limits->perMinute($metric);
    }
}
