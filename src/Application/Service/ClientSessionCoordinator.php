<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;

final readonly class ClientSessionCoordinator
{
    public function __construct(
        private InMemoryClientRegistry $clientRegistry,
        private ClientDisconnectionHandler $disconnectionHandler,
        private MessageRouter $messageRouter,
    ) {
    }

    public function open(ClientConnectionInterface $connection, ConnectionInfo $connectionInfo): RelayClient
    {
        return $this->clientRegistry->registerClient($connection, $connectionInfo);
    }

    public function route(RelayClient $client, string $message): void
    {
        $this->messageRouter->route($client, $message);
    }

    public function close(ClientId $clientId): void
    {
        $this->disconnectionHandler->disconnect($clientId);
    }
}
