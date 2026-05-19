<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\RateLimiting;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\TokenBucketRateLimiter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class TokenBucketRateLimiterTest extends TestCase
{
    public function testAllowsRequestsWithinCapacity(): void
    {
        $limiter = new TokenBucketRateLimiter(
            $this->configReturning(new RateLimitConfig(3, 3)),
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
        $config = new class(new RateLimitConfig(1, 1)) implements RelayConfigInterface {
            public function __construct(public RateLimitConfig $rateLimitConfig)
            {
            }

            public function getRateLimitConfig(): RateLimitConfig
            {
                return $this->rateLimitConfig;
            }

            public function getHost(): string
            {
                return '';
            }

            public function getPort(): int
            {
                return 0;
            }

            public function getMaxConnections(): int
            {
                return 0;
            }

            public function getRelayInfo(): Nip11Info
            {
                throw new RuntimeException('not used');
            }

            public function getRelayUrl(): RelayUrl
            {
                throw new RuntimeException('not used');
            }

            public function getTrustedProxies(): array
            {
                return [];
            }
        };

        $limiter = new TokenBucketRateLimiter($config, RateLimitMetric::Events);
        $limiter->checkLimit('key');

        $config->rateLimitConfig = new RateLimitConfig(10, 10);
        $limiter->reset('key');
        for ($i = 0; $i < 10; ++$i) {
            $limiter->checkLimit('key');
        }

        $this->expectException(RateLimitException::class);
        $limiter->checkLimit('key');
    }

    public function testMetricSelectsCorrectField(): void
    {
        $config = $this->configReturning(new RateLimitConfig(eventsPerMinute: 1, subscriptionsPerMinute: 10));
        $eventLimiter = new TokenBucketRateLimiter($config, RateLimitMetric::Events);
        $subLimiter = new TokenBucketRateLimiter($config, RateLimitMetric::Subscriptions);

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
            $this->configReturning(new RateLimitConfig(60, 60)),
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
            $this->configReturning(new RateLimitConfig(60, 60)),
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

    private function configReturning(RateLimitConfig $rateLimitConfig): RelayConfigInterface
    {
        $config = $this->createMock(RelayConfigInterface::class);
        $config->method('getRateLimitConfig')->willReturn($rateLimitConfig);

        return $config;
    }
}
