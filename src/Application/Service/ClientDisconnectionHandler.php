<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Psr\Log\LoggerInterface;

final class ClientDisconnectionHandler
{
    // Deliberate: coordinates teardown across three registries plus logging — see ADR-0010
    public function __construct(
        private readonly ClientRegistryInterface $registry,
        private readonly SubscriptionRegistryInterface $subscriptionRegistry,
        private readonly AuthenticatedSessionsInterface $authenticatedSessions,
        private readonly LoggerInterface $logger,
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

        $this->logger->info('Client disconnected', [
            'client_id' => (string) $clientId,
            'total_clients' => $this->registry->getClientCount(),
        ]);
    }
}
