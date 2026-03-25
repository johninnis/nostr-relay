<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\RateLimiting;

use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitToken;

final class TokenBucketRateLimiter implements RateLimiterInterface
{
    private const EVICTION_THRESHOLD = 1000;

    private array $buckets = [];

    public function __construct(
        private readonly int $capacity = 100,
        private readonly float $refillRate = 10.0,
    ) {
    }

    public function checkLimit(string $key): void
    {
        $this->evictStaleBuckets();

        $bucket = $this->getBucket($key);

        $now = microtime(true);
        $elapsed = $now - $bucket->getLastRefill();

        $tokensToAdd = min(
            $this->capacity - $bucket->getTokens(),
            $elapsed * $this->refillRate
        );

        $bucket = $bucket->withAddedTokens($tokensToAdd, $now);

        if (!$bucket->hasTokens()) {
            $this->buckets[$key] = $bucket;
            throw RateLimitException::forKey($key);
        }

        $this->buckets[$key] = $bucket->withConsumedToken();
    }

    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    private function getBucket(string $key): RateLimitToken
    {
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = new RateLimitToken(
                (float) $this->capacity,
                microtime(true)
            );
        }

        return $this->buckets[$key];
    }

    private function evictStaleBuckets(): void
    {
        if (count($this->buckets) < self::EVICTION_THRESHOLD) {
            return;
        }

        $refillThreshold = microtime(true) - ($this->capacity / $this->refillRate);

        $this->buckets = array_filter(
            $this->buckets,
            static fn (RateLimitToken $bucket) => $bucket->getLastRefill() > $refillThreshold
        );
    }
}
