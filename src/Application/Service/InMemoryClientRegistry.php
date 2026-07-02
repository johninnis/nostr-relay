<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\RandomBytesGeneratorInterface;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Domain\Collection\RelayClientCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use Override;
use Psr\Log\LoggerInterface;

final class InMemoryClientRegistry implements ClientRegistryInterface
{
    private const int CLIENT_ID_BYTES = 16;

    // Deliberate: in-memory single-process registry of live connections, not a swappable store — see ADR-0008
    /** @var array<string, RelayClient> */
    private array $clients = [];
    /** @var array<string, ClientConnectionInterface> */
    private array $connections = [];
    /** @var array<string, SessionCounters> */
    private array $counters = [];

    // Deliberate: registry coordinates metrics, id generation, logging and a max-connections bound — see ADR-0010
    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
        private readonly RandomBytesGeneratorInterface $randomBytes,
        private readonly LoggerInterface $logger,
        private readonly int $maxConnections = 1000,
    ) {
    }

    public function registerClient(ClientConnectionInterface $connection, ConnectionInfo $connectionInfo): RelayClient
    {
        if (count($this->clients) >= $this->maxConnections) {
            throw ConnectionException::maxConnectionsReached($connectionInfo->getIpAddress());
        }

        $clientId = ClientId::fromBytes($this->randomBytes->bytes(self::CLIENT_ID_BYTES));
        $client = new RelayClient($clientId, $connectionInfo);

        $key = (string) $clientId;
        $this->clients[$key] = $client;
        $this->connections[$key] = $connection;
        $this->counters[$key] = SessionCounters::empty();
        $this->metrics->incrementActiveConnections();

        $this->logger->info('Client connected', [
            'client_id' => $key,
            'ip' => (string) $connectionInfo->getIpAddress(),
            'total_clients' => count($this->clients),
        ]);

        return $client;
    }

    #[Override]
    public function removeClient(ClientId $clientId): void
    {
        $key = (string) $clientId;

        if (!isset($this->clients[$key])) {
            return;
        }

        unset($this->clients[$key], $this->connections[$key], $this->counters[$key]);
        $this->metrics->decrementActiveConnections();
    }

    #[Override]
    public function getConnection(ClientId $clientId): ?ClientConnectionInterface
    {
        return $this->connections[(string) $clientId] ?? null;
    }

    #[Override]
    public function recordEventSent(ClientId $clientId): void
    {
        $key = (string) $clientId;

        if (isset($this->counters[$key])) {
            $this->counters[$key] = $this->counters[$key]->withEventSent();
            $this->metrics->incrementEventsSent();
        }
    }

    #[Override]
    public function recordEventReceived(ClientId $clientId): void
    {
        $key = (string) $clientId;

        if (isset($this->counters[$key])) {
            $this->counters[$key] = $this->counters[$key]->withEventReceived();
            $this->metrics->incrementEventsReceived();
        }
    }

    #[Override]
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

    #[Override]
    public function getClient(ClientId $clientId): ?RelayClient
    {
        return $this->clients[(string) $clientId] ?? null;
    }

    #[Override]
    public function getClientCount(): int
    {
        return count($this->clients);
    }

    public function getAllClients(): RelayClientCollection
    {
        return new RelayClientCollection($this->clients);
    }
}
