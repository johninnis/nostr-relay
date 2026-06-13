<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Entity\RelayClientCollection;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use Psr\Log\LoggerInterface;

final class ClientManager
{
    private array $clients = [];
    private array $connections = [];
    private array $counters = [];

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
        $client = new RelayClient($clientId, $connectionInfo, $this->subscriptionLookup);

        $key = (string) $clientId;
        $this->clients[$key] = $client;
        $this->connections[$key] = $connection;
        $this->counters[$key] = SessionCounters::empty();
        $this->metrics->incrementActiveConnections();

        $this->logger->info('Client connected', [
            'client_id' => $key,
            'ip' => $connectionInfo->getIpAddress(),
            'total_clients' => count($this->clients),
        ]);

        return $client;
    }

    public function removeClient(ClientId $clientId): void
    {
        $key = (string) $clientId;

        if (!isset($this->clients[$key])) {
            return;
        }

        unset($this->clients[$key], $this->connections[$key], $this->counters[$key]);
        $this->metrics->decrementActiveConnections();
    }

    public function send(RelayClient $client, RelayMessage $message): void
    {
        $key = (string) $client->getId();
        $connection = $this->connections[$key] ?? null;

        if (!$connection instanceof ClientConnectionInterface) {
            return;
        }

        $connection->sendText($message->toJson());

        if ($message instanceof EventMessage) {
            $this->counters[$key] = $this->counters[$key]->withEventSent();
        }
    }

    public function recordEventReceived(ClientId $clientId): void
    {
        $key = (string) $clientId;

        if (isset($this->counters[$key])) {
            $this->counters[$key] = $this->counters[$key]->withEventReceived();
        }
    }

    public function recordEventAccepted(ClientId $clientId): void
    {
        $key = (string) $clientId;

        if (isset($this->counters[$key])) {
            $this->counters[$key] = $this->counters[$key]->withEventAccepted();
        }
    }

    public function getSessionCounters(ClientId $clientId): SessionCounters
    {
        return $this->counters[(string) $clientId] ?? SessionCounters::empty();
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
