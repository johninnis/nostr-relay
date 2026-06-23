<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientManagerTest extends TestCase
{
    private ClientManager $manager;

    protected function setUp(): void
    {
        $this->manager = $this->makeManager();
    }

    private function makeManager(?MetricsCollectorInterface $metrics = null): ClientManager
    {
        return new ClientManager(
            $metrics ?? $this->createStub(MetricsCollectorInterface::class),
            new NullLogger(),
            2,
        );
    }

    private function createConnectionInfo(): ConnectionInfo
    {
        return new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now());
    }

    public function testRegisterClientReturnsRelayClient(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementActiveConnections');
        $manager = $this->makeManager($metrics);

        $connection = $this->createStub(ClientConnectionInterface::class);
        $connectionInfo = $this->createConnectionInfo();

        $client = $manager->registerClient($connection, $connectionInfo);

        $this->assertSame($connectionInfo, $client->getConnectionInfo());
        $this->assertSame(1, $manager->getClientCount());
    }

    public function testRegisterClientThrowsWhenMaxConnectionsReached(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);

        $this->manager->registerClient($connection, $this->createConnectionInfo());
        $this->manager->registerClient($connection, $this->createConnectionInfo());

        $this->expectException(ConnectionException::class);

        $this->manager->registerClient($connection, $this->createConnectionInfo());
    }

    public function testRemoveClientDecrementsCount(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('decrementActiveConnections');
        $manager = $this->makeManager($metrics);

        $connection = $this->createStub(ClientConnectionInterface::class);
        $client = $manager->registerClient($connection, $this->createConnectionInfo());

        $manager->removeClient($client->getId());

        $this->assertSame(0, $manager->getClientCount());
    }

    public function testRemoveNonExistentClientIsNoOp(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('decrementActiveConnections');
        $manager = $this->makeManager($metrics);

        $manager->removeClient(ClientId::fromString('missing'));

        $this->assertSame(0, $manager->getClientCount());
    }

    public function testGetClientReturnsRegisteredClient(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $client = $this->manager->registerClient($connection, $this->createConnectionInfo());

        $found = $this->manager->getClient($client->getId());

        $this->assertNotNull($found);
        $this->assertTrue($client->getId()->equals($found->getId()));
    }

    public function testGetClientReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->manager->getClient(ClientId::fromString('unknown')));
    }

    public function testGetConnectionReturnsRegisteredConnection(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $client = $this->manager->registerClient($connection, $this->createConnectionInfo());

        $this->assertSame($connection, $this->manager->getConnection($client->getId()));
    }

    public function testGetConnectionReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->manager->getConnection(ClientId::fromString('unknown')));
    }

    public function testGetAllClientsReturnsCollection(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $this->manager->registerClient($connection, $this->createConnectionInfo());
        $this->manager->registerClient($connection, $this->createConnectionInfo());

        $all = $this->manager->getAllClients();

        $this->assertSame(2, $all->count());
    }

    public function testSessionCountersStartEmpty(): void
    {
        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $counters = $this->manager->getSessionCounters($client->getId());

        $this->assertSame(0, $counters->getEventsReceived());
        $this->assertSame(0, $counters->getEventsAccepted());
        $this->assertSame(0, $counters->getEventsSent());
    }

    public function testRecordEventReceivedAndAcceptedUpdateCounters(): void
    {
        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $this->manager->recordEventReceived($client->getId());
        $this->manager->recordEventReceived($client->getId());
        $this->manager->recordEventAccepted($client->getId());

        $counters = $this->manager->getSessionCounters($client->getId());
        $this->assertSame(2, $counters->getEventsReceived());
        $this->assertSame(1, $counters->getEventsAccepted());
    }

    public function testRecordEventSentUpdatesCounter(): void
    {
        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $this->manager->recordEventSent($client->getId());

        $this->assertSame(1, $this->manager->getSessionCounters($client->getId())->getEventsSent());
    }
}
