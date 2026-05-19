<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RateLimitConfigTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $config = new RateLimitConfig(60, 30);

        $this->assertSame(60, $config->getEventsPerMinute());
        $this->assertSame(30, $config->getSubscriptionsPerMinute());
    }

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
}
