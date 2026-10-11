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
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;

final class TokenBucketRateLimiterTest extends TestCase
{
    private const string ADDRESS = '192.0.2.1';
    private const int LATE_ARRIVAL = 9_999_999;

    public function testAllowsRequestsWithinCapacity(): void
    {
        $limiter = self::limiter(3, new FrozenMonotonicClock(1000.0));

        $this->assertSame([true, true, true], self::consume($limiter, IpAddress::fromString(self::ADDRESS), 3));
    }

    public function testRefusesARequestBeyondCapacity(): void
    {
        $limiter = self::limiter(3, new FrozenMonotonicClock(1000.0));
        self::consume($limiter, IpAddress::fromString(self::ADDRESS), 3);

        $this->assertFalse($limiter->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testAllowsTheOneRequestACapacityOfOneHolds(): void
    {
        $this->assertTrue(self::limiter(1, new FrozenMonotonicClock(1000.0))->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testCapacityIsReadOnEachCheck(): void
    {
        $limiter = self::limiterWidenedFromOneToTenAfterAFirstRequest();

        $this->assertSame(array_fill(0, 10, true), self::consume($limiter, IpAddress::fromString('192.0.2.2'), 10));
    }

    public function testAWidenedCapacityStillRefusesBeyondItsNewLimit(): void
    {
        $limiter = self::limiterWidenedFromOneToTenAfterAFirstRequest();
        self::consume($limiter, IpAddress::fromString('192.0.2.2'), 10);

        $this->assertFalse($limiter->tryConsume(IpAddress::fromString('192.0.2.2')));
    }

    public function testTheEventsMetricAllowsItsOwnRate(): void
    {
        $this->assertTrue(self::limiterFor(RateLimitMetric::Events)->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testTheSubscriptionsMetricAllowsItsOwnRate(): void
    {
        $this->assertSame(array_fill(0, 10, true), self::consume(self::limiterFor(RateLimitMetric::Subscriptions), IpAddress::fromString(self::ADDRESS), 10));
    }

    public function testMetricSelectsCorrectField(): void
    {
        $limiter = self::limiterFor(RateLimitMetric::Events);
        $limiter->tryConsume(IpAddress::fromString(self::ADDRESS));

        $this->assertFalse($limiter->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testABucketHoldsAMinutesRate(): void
    {
        $limiter = self::limiter(60, new FrozenMonotonicClock(1000.0));

        $this->assertSame(array_fill(0, 60, true), self::consume($limiter, IpAddress::fromString(self::ADDRESS), 60));
    }

    public function testADrainedBucketRefuses(): void
    {
        $this->assertFalse(self::drainedLimiter(new FrozenMonotonicClock(1000.0))->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testTokensRefillOverElapsedTime(): void
    {
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = self::drainedLimiter($clock);

        $clock->advance(1.0);

        $this->assertTrue($limiter->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testOneSecondRefillsOneTokenAtSixtyPerMinute(): void
    {
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = self::drainedLimiter($clock);

        $clock->advance(1.0);
        $limiter->tryConsume(IpAddress::fromString(self::ADDRESS));

        $this->assertFalse($limiter->tryConsume(IpAddress::fromString(self::ADDRESS)));
    }

    public function testStaleBucketsEvictedAfterInactivity(): void
    {
        $this->assertArrayNotHasKey((string) self::ipForIndex(0), self::buckets(self::limiterAfterStaleEviction()));
    }

    public function testTheRequestThatTriggersEvictionKeepsItsBucket(): void
    {
        $this->assertArrayHasKey((string) self::ipForIndex(self::LATE_ARRIVAL), self::buckets(self::limiterAfterStaleEviction()));
    }

    public function testEvictionShrinksTheBucketTableBelowTheThreshold(): void
    {
        $this->assertLessThan(self::limit('EVICTION_THRESHOLD'), count(self::buckets(self::limiterAfterStaleEviction())));
    }

    public function testBucketTableIsBoundedWhenAllBucketsAreFresh(): void
    {
        $limiter = self::limiter(60, new FrozenMonotonicClock(1000.0));

        for ($i = 0; $i < 6000; ++$i) {
            $limiter->tryConsume(self::ipForIndex($i));
        }

        $this->assertLessThanOrEqual(self::limit('HARD_MAX_BUCKETS'), count(self::buckets($limiter)));
    }

    public function testOldestBucketsEvictedFirstWhenAtHardCap(): void
    {
        $this->assertArrayNotHasKey((string) self::ipForIndex(0), self::buckets(self::limiterFilledPastTheHardCap()));
    }

    public function testTheNewestBucketSurvivesEvictionAtHardCap(): void
    {
        $this->assertArrayHasKey((string) self::ipForIndex(self::limit('HARD_MAX_BUCKETS') + 499), self::buckets(self::limiterFilledPastTheHardCap()));
    }

    private static function limiter(int $perMinute, FrozenMonotonicClock $clock): TokenBucketRateLimiter
    {
        return new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig($perMinute, $perMinute)),
            RateLimitMetric::Events,
            $clock,
        );
    }

    private static function limiterFor(RateLimitMetric $metric): TokenBucketRateLimiter
    {
        return new TokenBucketRateLimiter(
            new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 1, subscriptionsPerMinute: 10)),
            $metric,
            new FrozenMonotonicClock(1000.0),
        );
    }

    private static function limiterWidenedFromOneToTenAfterAFirstRequest(): TokenBucketRateLimiter
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
        $limiter->tryConsume(IpAddress::fromString(self::ADDRESS));
        $policy->limit = 10;

        return $limiter;
    }

    private static function drainedLimiter(FrozenMonotonicClock $clock): TokenBucketRateLimiter
    {
        $limiter = self::limiter(60, $clock);
        self::consume($limiter, IpAddress::fromString(self::ADDRESS), 60);

        return $limiter;
    }

    private static function limiterAfterStaleEviction(): TokenBucketRateLimiter
    {
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = self::limiter(60, $clock);

        for ($i = 0; $i < self::limit('EVICTION_THRESHOLD'); ++$i) {
            $limiter->tryConsume(self::ipForIndex($i));
        }

        $clock->advance(120.0);
        $limiter->tryConsume(self::ipForIndex(self::LATE_ARRIVAL));

        return $limiter;
    }

    private static function limiterFilledPastTheHardCap(): TokenBucketRateLimiter
    {
        $clock = new FrozenMonotonicClock(1000.0);
        $limiter = self::limiter(60, $clock);

        for ($i = 0; $i < self::limit('HARD_MAX_BUCKETS') + 500; ++$i) {
            $clock->advance(0.001);
            $limiter->tryConsume(self::ipForIndex($i));
        }

        return $limiter;
    }

    /**
     * @return list<bool>
     */
    private static function consume(TokenBucketRateLimiter $limiter, IpAddress $ipAddress, int $times): array
    {
        return array_map(static fn (): bool => $limiter->tryConsume($ipAddress), array_fill(0, $times, null));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function buckets(TokenBucketRateLimiter $limiter): array
    {
        $buckets = new ReflectionProperty($limiter, 'buckets')->getValue($limiter);

        return is_array($buckets) ? $buckets : throw new LogicException('A token bucket limiter keeps its buckets in an array');
    }

    private static function limit(string $name): int
    {
        $limit = new ReflectionClassConstant(TokenBucketRateLimiter::class, $name)->getValue();

        return is_int($limit) ? $limit : throw new LogicException(sprintf('The limiter constant %s is not an integer', $name));
    }

    private static function ipForIndex(int $index): IpAddress
    {
        return IpAddress::fromString(long2ip($index));
    }
}
