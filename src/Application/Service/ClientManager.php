<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Entity\RelayClientCollection;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Psr\Log\LoggerInterface;

final class ClientManager
{
    private array $clients = [];

    public function __construct(
        private readonly SubscriptionLookupInterface $subscriptionLookup,
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
        private readonly int $maxConnections = 1000,
    ) {
    }

    public function registerClient(ClientConnectionInterface $connection, ConnectionInfo $connectionInfo): RelayClient
    {
        if (count($this->clients) >= $this->maxConnections) {
            throw ConnectionException::maxConnectionsReached($connectionInfo->getIpAddress());
        }

        $clientId = ClientId::generate();
        $client = new RelayClient($clientId, $connection, $connectionInfo, $this->subscriptionLookup);

        $this->clients[(string) $clientId] = $client;
        $this->metrics->incrementActiveConnections();

        $this->logger->info('Client connected', [
            'client_id' => (string) $clientId,
            'ip' => $connectionInfo->getIpAddress(),
            'total_clients' => count($this->clients),
        ]);

        return $client;
    }

    public function removeClient(ClientId $clientId): void
    {
        if (!isset($this->clients[(string) $clientId])) {
            return;
        }

        unset($this->clients[(string) $clientId]);
        $this->metrics->decrementActiveConnections();
    }

    public function getClient(ClientId $clientId): ?RelayClient
    {
        return $this->clients[(string) $clientId] ?? null;
    }

    public function getClientCount(): int
    {
        return count($this->clients);
    }

    public function getAllClients(): RelayClientCollection
    {
        return new RelayClientCollection($this->clients);
    }
}
