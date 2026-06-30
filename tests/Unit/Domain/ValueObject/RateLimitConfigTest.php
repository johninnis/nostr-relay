<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateLimitConfigTest extends TestCase
{
    public function testPerMinuteSelectsByMetric(): void
    {
        $config = new RateLimitConfig(120, 30);

        $this->assertSame(120, $config->perMinute(RateLimitMetric::Events));
        $this->assertSame(30, $config->perMinute(RateLimitMetric::Subscriptions));
    }

    public function testRejectsZeroEventsPerMinute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitConfig(0, 30);
    }

    public function testRejectsNegativeSubscriptionsPerMinute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimitConfig(60, -1);
    }

    public function testToArrayUsesWireKeys(): void
    {
        $this->assertSame(
            ['events_per_minute' => 120, 'subscriptions_per_minute' => 30],
            new RateLimitConfig(120, 30)->toArray(),
        );
    }

    public function testFromArrayRoundTripsToArray(): void
    {
        $config = new RateLimitConfig(200, 50);

        $restored = RateLimitConfig::fromArray($config->toArray());

        $this->assertNotNull($restored);
        $this->assertSame(200, $restored->perMinute(RateLimitMetric::Events));
        $this->assertSame(50, $restored->perMinute(RateLimitMetric::Subscriptions));
    }

    public function testFromArrayCoercesNumericStrings(): void
    {
        $config = RateLimitConfig::fromArray(['events_per_minute' => '90', 'subscriptions_per_minute' => '15']);

        $this->assertNotNull($config);
        $this->assertSame(90, $config->perMinute(RateLimitMetric::Events));
        $this->assertSame(15, $config->perMinute(RateLimitMetric::Subscriptions));
    }

    public function testFromArrayReturnsNullWhenKeyMissing(): void
    {
        $this->assertNull(RateLimitConfig::fromArray(['events_per_minute' => 60]));
    }

    public function testFromArrayReturnsNullWhenNotPositive(): void
    {
        $this->assertNull(RateLimitConfig::fromArray(['events_per_minute' => 0, 'subscriptions_per_minute' => 30]));
    }

    public function testFromArrayReturnsNullWhenNonNumeric(): void
    {
        $this->assertNull(RateLimitConfig::fromArray(['events_per_minute' => 'fast', 'subscriptions_per_minute' => 30]));
    }
}
