<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

final class ClientDisconnectionHandler
{
    public function __construct(
        private readonly ClientRegistryInterface $registry,
        private readonly SubscriptionRegistryInterface $subscriptionRegistry,
        private readonly AuthenticatedSessionsInterface $authenticatedSessions,
    ) {
    }

    public function disconnect(ClientId $clientId): void
    {
        if (null === $this->registry->getClient($clientId)) {
            return;
        }

        $this->subscriptionRegistry->removeAllForClient($clientId);
        $this->authenticatedSessions->removeClient($clientId);
        $this->registry->removeClient($clientId);
    }
}
