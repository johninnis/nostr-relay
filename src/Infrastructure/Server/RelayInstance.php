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
    // Deliberate: assembled relay aggregate of the server and the registries it exposes — see ADR-0010
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
