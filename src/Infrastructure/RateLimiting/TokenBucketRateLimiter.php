<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\RateLimiting;

use Innis\Nostr\Relay\Application\Port\MonotonicClockInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitToken;
use Override;

final class TokenBucketRateLimiter implements RateLimiterInterface
{
    private const int EVICTION_THRESHOLD = 1000;
    private const int HARD_MAX_BUCKETS = 5000;
    private const float STALE_AFTER_SECONDS = 60.0;
    private const float OVERFLOW_RETAIN_RATIO = 0.9;
    private const float EVICTION_INTERVAL_SECONDS = 5.0;

    /** @var array<string, RateLimitToken> */
    private array $buckets = [];
    private float $lastEvictionAt = 0.0;

    public function __construct(
        private readonly RateLimitPolicyInterface $rateLimitPolicy,
        private readonly RateLimitMetric $metric,
        private readonly MonotonicClockInterface $clock,
    ) {
    }

    #[Override]
    public function tryConsume(IpAddress $ipAddress): bool
    {
        $key = (string) $ipAddress;
        $capacity = (float) $this->rateLimitPolicy->limitFor($this->metric);
        $now = $this->clock->now();

        $this->evictStaleBuckets($now);

        $bucket = $this->getBucket($key, $capacity, $now)->refilled($now, $capacity);

        if (!$bucket->hasTokens()) {
            $this->buckets[$key] = $bucket;

            return false;
        }

        $this->buckets[$key] = $bucket->withConsumedToken();

        return true;
    }

    private function getBucket(string $key, float $capacity, float $now): RateLimitToken
    {
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = new RateLimitToken($capacity, $now);
        }

        return $this->buckets[$key];
    }

    private function evictStaleBuckets(float $now): void
    {
        if (count($this->buckets) < self::EVICTION_THRESHOLD) {
            return;
        }

        if ($now - $this->lastEvictionAt < self::EVICTION_INTERVAL_SECONDS
            && count($this->buckets) < self::HARD_MAX_BUCKETS) {
            return;
        }

        $this->lastEvictionAt = $now;
        $staleBefore = $now - self::STALE_AFTER_SECONDS;

        $this->buckets = array_filter(
            $this->buckets,
            static fn (RateLimitToken $bucket) => $bucket->getLastRefill() > $staleBefore
        );

        if (count($this->buckets) >= self::HARD_MAX_BUCKETS) {
            $this->evictOldestBuckets();
        }
    }

    private function evictOldestBuckets(): void
    {
        uasort(
            $this->buckets,
            static fn (RateLimitToken $a, RateLimitToken $b) => $a->getLastRefill() <=> $b->getLastRefill()
        );

        $retain = (int) (self::HARD_MAX_BUCKETS * self::OVERFLOW_RETAIN_RATIO);
        $this->buckets = array_slice($this->buckets, -$retain, null, true);
    }
}
