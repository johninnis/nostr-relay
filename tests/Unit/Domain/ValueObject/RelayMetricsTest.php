<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Closure;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelayMetricsTest extends TestCase
{
    private const int STARTED_AT = 1700000000;

    /**
     * @param Closure(RelayMetrics): int $read
     */
    #[DataProvider('counters')]
    public function testKeepsEachCounter(Closure $read, int $expected): void
    {
        $this->assertSame($expected, $read(new RelayMetrics(10, 500, 300, 25, Timestamp::fromInt(self::STARTED_AT))));
    }

    /**
     * @param Closure(RelayMetrics): int $read
     */
    #[DataProvider('counterReaders')]
    public function testACounterMayBeZero(Closure $read): void
    {
        $this->assertSame(0, $read(new RelayMetrics(0, 0, 0, 0, Timestamp::fromInt(self::STARTED_AT))));
    }

    public function testKeepsTheStartTime(): void
    {
        $metrics = new RelayMetrics(10, 500, 300, 25, Timestamp::fromInt(self::STARTED_AT));

        $this->assertTrue(Timestamp::fromInt(self::STARTED_AT)->equals($metrics->getStartedAt()));
    }

    /**
     * @return iterable<string, array{Closure(RelayMetrics): int, int}>
     */
    public static function counters(): iterable
    {
        yield 'active connections' => [static fn (RelayMetrics $metrics): int => $metrics->getActiveConnections(), 10];
        yield 'events received' => [static fn (RelayMetrics $metrics): int => $metrics->getTotalEventsReceived(), 500];
        yield 'events sent' => [static fn (RelayMetrics $metrics): int => $metrics->getTotalEventsSent(), 300];
        yield 'subscriptions' => [static fn (RelayMetrics $metrics): int => $metrics->getTotalSubscriptions(), 25];
    }

    /**
     * @return iterable<string, array{Closure(RelayMetrics): int}>
     */
    public static function counterReaders(): iterable
    {
        foreach (self::counters() as $counter => [$read]) {
            yield $counter => [$read];
        }
    }
}
