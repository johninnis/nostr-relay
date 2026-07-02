<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Socket\SocketAddress;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Domain\Collection\RelayClientCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;

final class RelayInstance
{
    // Deliberate: the assembled relay aggregate — the server plus the registries and metrics it exposes for lifecycle and inspection; an assembled whole, not a behavioural unit to split.
    public function __construct(
        private readonly AmphpRelayServer $server,
        private readonly EventDistributor $distributor,
        private readonly InMemorySubscriptionRegistry $subscriptionManager,
        private readonly InMemoryClientRegistry $clientManager,
        private readonly MetricsCollectorInterface $metrics,
    ) {
    }

    public function start(): void
    {
        $this->server->start();
    }

    public function stop(): void
    {
        $this->server->stop();
    }

    public function getListeningAddress(): ?SocketAddress
    {
        return $this->server->getListeningAddress();
    }

    public function distributeEvent(Event $event): void
    {
        $this->distributor->distributeToSubscribers($event);
    }

    public function getMetrics(): RelayMetrics
    {
        return $this->metrics->getMetrics();
    }

    public function getClients(): RelayClientCollection
    {
        return $this->clientManager->getAllClients();
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->subscriptionManager->getAllSubscriptions();
    }

    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection
    {
        return $this->subscriptionManager->getSubscriptionsForClient($clientId);
    }

    public function getSessionCounters(ClientId $clientId): SessionCounters
    {
        return $this->clientManager->getSessionCounters($clientId);
    }
}
