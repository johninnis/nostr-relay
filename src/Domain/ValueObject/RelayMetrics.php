<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class RelayMetrics
{
    public function __construct(
        private int $activeConnections,
        private int $totalEventsReceived,
        private int $totalEventsSent,
        private int $totalSubscriptions,
        private Timestamp $startedAt,
    ) {
    }

    public function getActiveConnections(): int
    {
        return $this->activeConnections;
    }

    public function getTotalEventsReceived(): int
    {
        return $this->totalEventsReceived;
    }

    public function getTotalEventsSent(): int
    {
        return $this->totalEventsSent;
    }

    public function getTotalSubscriptions(): int
    {
        return $this->totalSubscriptions;
    }

    public function getStartedAt(): Timestamp
    {
        return $this->startedAt;
    }
}
