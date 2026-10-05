<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\RequestHandler;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Relay\Application\Service\ClientSessionCoordinator;
use Innis\Nostr\Relay\Domain\Collection\RelayClientCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;

final class RelayInstance
{
    public function __construct(
        private readonly RequestHandler $requestHandler,
        private readonly ClientSessionCoordinator $sessionCoordinator,
        private readonly RelayIntrospection $introspection,
    ) {
    }

    public function getRequestHandler(): RequestHandler
    {
        return $this->requestHandler;
    }

    public function getSessionCoordinator(): ClientSessionCoordinator
    {
        return $this->sessionCoordinator;
    }

    public function getIntrospection(): RelayIntrospection
    {
        return $this->introspection;
    }

    public function getMetrics(): RelayMetrics
    {
        return $this->introspection->getMetrics();
    }

    public function getClients(): RelayClientCollection
    {
        return $this->introspection->getClients();
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->introspection->getSubscriptions();
    }

    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection
    {
        return $this->introspection->getSubscriptionsForClient($clientId);
    }

    public function getSessionCounters(ClientId $clientId): SessionCounters
    {
        return $this->introspection->getSessionCounters($clientId);
    }
}
