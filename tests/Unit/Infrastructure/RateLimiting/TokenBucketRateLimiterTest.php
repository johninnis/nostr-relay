<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\RateLimiting;

use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\TokenBucketRateLimiter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TokenBucketRateLimiterTest extends TestCase
{
    public function testAllowsRequestsWithinCapacity(): void
    {
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(3, 3)),
            RateLimitMetric::Events,
        );

        $limiter->checkLimit('key');
        $limiter->checkLimit('key');
        $limiter->checkLimit('key');

        $this->expectException(RateLimitException::class);
        $limiter->checkLimit('key');
    }

    public function testCapacityIsReadOnEachCheck(): void
    {
        $policy = new class(1) implements RateLimitPolicyInterface {
            public function __construct(public int $limit)
            {
            }

            public function limitFor(RateLimitMetric $metric): int
            {
                return $this->limit;
            }
        };

        $limiter = new TokenBucketRateLimiter($policy, RateLimitMetric::Events);
        $limiter->checkLimit('key');

        $policy->limit = 10;
        $limiter->reset('key');
        for ($i = 0; $i < 10; ++$i) {
            $limiter->checkLimit('key');
        }

        $this->expectException(RateLimitException::class);
        $limiter->checkLimit('key');
    }

    public function testMetricSelectsCorrectField(): void
    {
        $policy = new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 1, subscriptionsPerMinute: 10));
        $eventLimiter = new TokenBucketRateLimiter($policy, RateLimitMetric::Events);
        $subLimiter = new TokenBucketRateLimiter($policy, RateLimitMetric::Subscriptions);

        $eventLimiter->checkLimit('key');
        for ($i = 0; $i < 10; ++$i) {
            $subLimiter->checkLimit('key');
        }

        $this->expectException(RateLimitException::class);
        $eventLimiter->checkLimit('key');
    }

    public function testBucketTableIsBoundedWhenAllBucketsAreFresh(): void
    {
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(60, 60)),
            RateLimitMetric::Events,
        );

        for ($i = 0; $i < 6000; ++$i) {
            $limiter->checkLimit('key-'.$i);
        }

        $reflection = new ReflectionClass($limiter);
        $buckets = $reflection->getProperty('buckets')->getValue($limiter);
        $hardMax = $reflection->getConstant('HARD_MAX_BUCKETS');

        $this->assertIsArray($buckets);
        $this->assertIsInt($hardMax);
        $this->assertLessThanOrEqual($hardMax, count($buckets));
    }

    public function testOldestBucketsEvictedFirstWhenAtHardCap(): void
    {
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(60, 60)),
            RateLimitMetric::Events,
        );

        $reflection = new ReflectionClass($limiter);
        $hardMax = $reflection->getConstant('HARD_MAX_BUCKETS');
        assert(is_int($hardMax));

        for ($i = 0; $i < $hardMax + 500; ++$i) {
            $limiter->checkLimit('key-'.$i);
        }

        $buckets = $reflection->getProperty('buckets')->getValue($limiter);
        assert(is_array($buckets));

        $this->assertArrayNotHasKey('key-0', $buckets);
        $this->assertArrayHasKey('key-'.($hardMax + 499), $buckets);
    }
}
