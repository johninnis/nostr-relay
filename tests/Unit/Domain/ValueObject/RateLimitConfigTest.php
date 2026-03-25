<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use PHPUnit\Framework\TestCase;

final class RateLimitConfigTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $config = new RateLimitConfig(60, 30);

        $this->assertSame(60, $config->getEventsPerMinute());
        $this->assertSame(30, $config->getSubscriptionsPerMinute());
    }

    public function testEventsRefillRateCalculation(): void
    {
        $config = new RateLimitConfig(120, 60);

        $this->assertSame(2.0, $config->getEventsRefillRate());
    }

    public function testSubscriptionsRefillRateCalculation(): void
    {
        $config = new RateLimitConfig(120, 60);

        $this->assertSame(1.0, $config->getSubscriptionsRefillRate());
    }

    public function testRefillRateWithNonDivisibleValues(): void
    {
        $config = new RateLimitConfig(100, 45);

        $this->assertEqualsWithDelta(100 / 60, $config->getEventsRefillRate(), 0.0001);
        $this->assertEqualsWithDelta(45 / 60, $config->getSubscriptionsRefillRate(), 0.0001);
    }
}
