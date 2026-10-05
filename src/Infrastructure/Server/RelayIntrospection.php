<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Domain\Collection\RelayClientCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;

final readonly class RelayIntrospection
{
    public function __construct(
        private MetricsCollectorInterface $metrics,
        private InMemoryClientRegistry $clientRegistry,
        private InMemorySubscriptionRegistry $subscriptionRegistry,
    ) {
    }

    public function getMetrics(): RelayMetrics
    {
        return $this->metrics->getMetrics();
    }

    public function getClients(): RelayClientCollection
    {
        return $this->clientRegistry->getAllClients();
    }

    public function getSessionCounters(ClientId $clientId): SessionCounters
    {
        return $this->clientRegistry->getSessionCounters($clientId);
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->subscriptionRegistry->getAllSubscriptions();
    }

    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection
    {
        return $this->subscriptionRegistry->getSubscriptionsForClient($clientId);
    }
}
