<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Override;

// Deliberate: the cap is enforced here, at registration, the single allocation point every session must pass through — see ADR-0032
final readonly class CappedClientRegistry implements ClientRegistryInterface
{
    public function __construct(
        private ClientRegistryInterface $registry,
        private int $maxConnections,
    ) {
    }

    #[Override]
    public function registerClient(ClientConnectionInterface $connection, ConnectionInfo $connectionInfo): RelayClient
    {
        if ($this->registry->getClientCount() >= $this->maxConnections) {
            throw ConnectionException::connectionLimitReached($this->maxConnections);
        }

        return $this->registry->registerClient($connection, $connectionInfo);
    }

    #[Override]
    public function getClient(ClientId $clientId): ?RelayClient
    {
        return $this->registry->getClient($clientId);
    }

    #[Override]
    public function getClientCount(): int
    {
        return $this->registry->getClientCount();
    }

    #[Override]
    public function getConnection(ClientId $clientId): ?ClientConnectionInterface
    {
        return $this->registry->getConnection($clientId);
    }

    #[Override]
    public function removeClient(ClientId $clientId): void
    {
        $this->registry->removeClient($clientId);
    }

    #[Override]
    public function recordEventReceived(ClientId $clientId): void
    {
        $this->registry->recordEventReceived($clientId);
    }

    #[Override]
    public function recordEventAccepted(ClientId $clientId): void
    {
        $this->registry->recordEventAccepted($clientId);
    }

    #[Override]
    public function recordEventSent(ClientId $clientId): void
    {
        $this->registry->recordEventSent($clientId);
    }
}
