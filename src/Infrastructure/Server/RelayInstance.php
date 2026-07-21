<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\RequestHandler;
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
    // Deliberate: assembled relay aggregate of the request handler and the registries it exposes — see ADR-0010
    public function __construct(
        private readonly RequestHandler $requestHandler,
        private readonly EventDistributor $distributor,
        private readonly InMemorySubscriptionRegistry $subscriptionRegistry,
        private readonly InMemoryClientRegistry $clientRegistry,
        private readonly MetricsCollectorInterface $metrics,
    ) {
    }

    public function getRequestHandler(): RequestHandler
    {
        return $this->requestHandler;
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
        return $this->clientRegistry->getAllClients();
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->subscriptionRegistry->getAllSubscriptions();
    }

    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection
    {
        return $this->subscriptionRegistry->getSubscriptionsForClient($clientId);
    }

    public function getSessionCounters(ClientId $clientId): SessionCounters
    {
        return $this->clientRegistry->getSessionCounters($clientId);
    }
}
