<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitConfigTest extends TestCase
{
    #[DataProvider('ratesByMetric')]
    public function testPerMinuteSelectsByMetric(RateLimitMetric $metric, int $expected): void
    {
        $this->assertSame($expected, new RateLimitConfig(120, 30)->perMinute($metric));
    }

    /**
     * @return iterable<string, array{RateLimitMetric, int}>
     */
    public static function ratesByMetric(): iterable
    {
        yield 'events' => [RateLimitMetric::Events, 120];
        yield 'subscriptions' => [RateLimitMetric::Subscriptions, 30];
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

        $this->assertEquals($config, RateLimitConfig::tryFromArray($config->toArray()));
    }

    public function testFromArrayCoercesNumericStrings(): void
    {
        $this->assertEquals(
            new RateLimitConfig(90, 15),
            RateLimitConfig::tryFromArray(['events_per_minute' => '90', 'subscriptions_per_minute' => '15']),
        );
    }

    public function testFromArrayReturnsNullWhenKeyMissing(): void
    {
        $this->assertNull(RateLimitConfig::tryFromArray(['events_per_minute' => 60]));
    }

    public function testFromArrayReturnsNullWhenNotPositive(): void
    {
        $this->assertNull(RateLimitConfig::tryFromArray(['events_per_minute' => 0, 'subscriptions_per_minute' => 30]));
    }

    public function testFromArrayReturnsNullWhenNonNumeric(): void
    {
        $this->assertNull(RateLimitConfig::tryFromArray(['events_per_minute' => 'fast', 'subscriptions_per_minute' => 30]));
    }
}
