<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\RateLimiting;

use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\TokenBucketRateLimiter;
use Innis\Nostr\Relay\Tests\Support\FrozenMonotonicClock;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TokenBucketRateLimiterTest extends TestCase
{
    public function testAllowsRequestsWithinCapacity(): void
    {
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(3, 3)),
            RateLimitMetric::Events,
            new FrozenMonotonicClock(1000.0),
        );

        $ip = IpAddress::fromString('192.0.2.1');
        $this->assertTrue($limiter->tryConsume($ip));
        $this->assertTrue($limiter->tryConsume($ip));
        $this->assertTrue($limiter->tryConsume($ip));
        $this->assertFalse($limiter->tryConsume($ip));
    }

    public function testCapacityIsReadOnEachCheck(): void
    {
        $policy = new class(1) implements RateLimitPolicyInterface {
            public function __construct(public int $limit)
            {
            }

            #[Override]
            public function limitFor(RateLimitMetric $metric): int
            {
                return $this->limit;
            }
        };

        $limiter = new TokenBucketRateLimiter($policy, RateLimitMetric::Events, new FrozenMonotonicClock(1000.0));
        $this->assertTrue($limiter->tryConsume(IpAddress::fromString('192.0.2.1')));

        $policy->limit = 10;
        $widened = IpAddress::fromString('192.0.2.2');
        for ($i = 0; $i < 10; ++$i) {
            $this->assertTrue($limiter->tryConsume($widened));
        }

        $this->assertFalse($limiter->tryConsume($widened));
    }

    public function testMetricSelectsCorrectField(): void
    {
        $policy = new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 1, subscriptionsPerMinute: 10));
        $clock = new FrozenMonotonicClock(1000.0);
        $eventLimiter = new TokenBucketRateLimiter($policy, RateLimitMetric::Events, $clock);
        $subLimiter = new TokenBucketRateLimiter($policy, RateLimitMetric::Subscriptions, $clock);

        $ip = IpAddress::fromString('192.0.2.1');
        $this->assertTrue($eventLimiter->tryConsume($ip));
        for ($i = 0; $i < 10; ++$i) {
            $this->assertTrue($subLimiter->tryConsume($ip));
        }

        $this->assertFalse($eventLimiter->tryConsume($ip));
    }

    public function testTokensRefillOverElapsedTime(): void
    {
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(60, 60)),
            RateLimitMetric::Events,
            $clock,
        );

        $ip = IpAddress::fromString('192.0.2.1');
        for ($i = 0; $i < 60; ++$i) {
            $this->assertTrue($limiter->tryConsume($ip));
        }

        $this->assertFalse($limiter->tryConsume($ip));

        $clock->advance(1.0);
        $this->assertTrue($limiter->tryConsume($ip));
        $this->assertFalse($limiter->tryConsume($ip));
    }

    public function testStaleBucketsEvictedAfterInactivity(): void
    {
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(60, 60)),
            RateLimitMetric::Events,
            $clock,
        );

        $reflection = new ReflectionClass($limiter);
        $threshold = $reflection->getConstant('EVICTION_THRESHOLD');
        assert(is_int($threshold));

        for ($i = 0; $i < $threshold; ++$i) {
            $limiter->tryConsume(self::ipForIndex($i));
        }

        $clock->advance(120.0);
        $limiter->tryConsume(self::ipForIndex(9_999_999));

        $buckets = $reflection->getProperty('buckets')->getValue($limiter);
        assert(is_array($buckets));

        $this->assertArrayNotHasKey((string) self::ipForIndex(0), $buckets);
        $this->assertArrayHasKey((string) self::ipForIndex(9_999_999), $buckets);
        $this->assertLessThan($threshold, count($buckets));
    }

    public function testBucketTableIsBoundedWhenAllBucketsAreFresh(): void
    {
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(60, 60)),
            RateLimitMetric::Events,
            new FrozenMonotonicClock(1000.0),
        );

        for ($i = 0; $i < 6000; ++$i) {
            $limiter->tryConsume(self::ipForIndex($i));
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
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(60, 60)),
            RateLimitMetric::Events,
            $clock,
        );

        $reflection = new ReflectionClass($limiter);
        $hardMax = $reflection->getConstant('HARD_MAX_BUCKETS');
        assert(is_int($hardMax));

        for ($i = 0; $i < $hardMax + 500; ++$i) {
            $clock->advance(0.001);
            $limiter->tryConsume(self::ipForIndex($i));
        }

        $buckets = $reflection->getProperty('buckets')->getValue($limiter);
        assert(is_array($buckets));

        $this->assertArrayNotHasKey((string) self::ipForIndex(0), $buckets);
        $this->assertArrayHasKey((string) self::ipForIndex($hardMax + 499), $buckets);
    }

    private static function ipForIndex(int $index): IpAddress
    {
        return IpAddress::fromString(long2ip($index));
    }
}
