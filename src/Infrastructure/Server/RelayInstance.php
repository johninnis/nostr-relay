<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\SubscriptionCollection;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClientCollection;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;

final class RelayInstance
{
    public function __construct(
        private readonly AmphpRelayServer $server,
        private readonly EventDistributor $distributor,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly ClientManager $clientManager,
        private readonly MetricsCollectorInterface $metrics,
    ) {
    }

    public function start(): void
    {
        $this->server->start();
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
}
