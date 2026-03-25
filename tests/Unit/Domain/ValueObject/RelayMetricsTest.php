<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;
use PHPUnit\Framework\TestCase;

final class RelayMetricsTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $startedAt = Timestamp::fromInt(1700000000);
        $metrics = new RelayMetrics(10, 500, 300, 25, $startedAt);

        $this->assertSame(10, $metrics->getActiveConnections());
        $this->assertSame(500, $metrics->getTotalEventsReceived());
        $this->assertSame(300, $metrics->getTotalEventsSent());
        $this->assertSame(25, $metrics->getTotalSubscriptions());
        $this->assertTrue($startedAt->equals($metrics->getStartedAt()));
    }

    public function testZeroValues(): void
    {
        $metrics = new RelayMetrics(0, 0, 0, 0, Timestamp::now());

        $this->assertSame(0, $metrics->getActiveConnections());
        $this->assertSame(0, $metrics->getTotalEventsReceived());
        $this->assertSame(0, $metrics->getTotalEventsSent());
        $this->assertSame(0, $metrics->getTotalSubscriptions());
    }
}
