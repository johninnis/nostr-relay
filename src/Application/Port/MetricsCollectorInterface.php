<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;

interface MetricsCollectorInterface
{
    public function incrementActiveConnections(): void;

    public function decrementActiveConnections(): void;

    public function incrementEventsReceived(): void;

    public function incrementEventsSent(): void;

    public function incrementSubscriptions(): void;

    public function decrementSubscriptions(): void;

    public function getMetrics(): RelayMetrics;
}
