<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Monitoring;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;

final class InMemoryMetricsCollector implements MetricsCollectorInterface
{
    private int $activeConnections = 0;
    private int $totalEventsReceived = 0;
    private int $totalEventsSent = 0;
    private int $totalSubscriptions = 0;
    private Timestamp $startedAt;

    public function __construct()
    {
        $this->startedAt = Timestamp::now();
    }

    public function incrementActiveConnections(): void
    {
        ++$this->activeConnections;
    }

    public function decrementActiveConnections(): void
    {
        $this->activeConnections = max(0, $this->activeConnections - 1);
    }

    public function incrementEventsReceived(): void
    {
        ++$this->totalEventsReceived;
    }

    public function incrementEventsSent(): void
    {
        ++$this->totalEventsSent;
    }

    public function incrementSubscriptions(): void
    {
        ++$this->totalSubscriptions;
    }

    public function decrementSubscriptions(): void
    {
        $this->totalSubscriptions = max(0, $this->totalSubscriptions - 1);
    }

    public function getMetrics(): RelayMetrics
    {
        return new RelayMetrics(
            $this->activeConnections,
            $this->totalEventsReceived,
            $this->totalEventsSent,
            $this->totalSubscriptions,
            $this->startedAt
        );
    }
}
