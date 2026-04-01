<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Psr\Log\LoggerInterface;

final class ClientDisconnectionHandler
{
    public function __construct(
        private readonly ClientManager $clientManager,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly AuthenticationManager $authManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function disconnect(ClientId $clientId): void
    {
        if (null === $this->clientManager->getClient($clientId)) {
            return;
        }

        $this->subscriptionManager->removeAllForClient($clientId);
        $this->authManager->removeClient($clientId);
        $this->clientManager->removeClient($clientId);

        $this->logger->info('Client disconnected', [
            'client_id' => (string) $clientId,
            'total_clients' => $this->clientManager->getClientCount(),
        ]);
    }
}
