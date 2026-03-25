<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientManagerTest extends TestCase
{
    private SubscriptionLookupInterface&MockObject $subscriptionLookup;
    private MetricsCollectorInterface&MockObject $metrics;
    private ClientManager $manager;

    protected function setUp(): void
    {
        $this->subscriptionLookup = $this->createMock(SubscriptionLookupInterface::class);
        $this->metrics = $this->createMock(MetricsCollectorInterface::class);
        $this->manager = new ClientManager(
            $this->subscriptionLookup,
            $this->metrics,
            new NullLogger(),
            2,
        );
    }

    private function createConnectionInfo(): ConnectionInfo
    {
        return new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now());
    }

    public function testRegisterClientReturnsRelayClient(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connectionInfo = $this->createConnectionInfo();

        $this->metrics->expects($this->once())->method('incrementActiveConnections');

        $client = $this->manager->registerClient($connection, $connectionInfo);

        $this->assertSame($connectionInfo, $client->getConnectionInfo());
        $this->assertSame(1, $this->manager->getClientCount());
    }

    public function testRegisterClientThrowsWhenMaxConnectionsReached(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);

        $this->manager->registerClient($connection, $this->createConnectionInfo());
        $this->manager->registerClient($connection, $this->createConnectionInfo());

        $this->expectException(ConnectionException::class);

        $this->manager->registerClient($connection, $this->createConnectionInfo());
    }

    public function testRemoveClientDecrementsCount(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $client = $this->manager->registerClient($connection, $this->createConnectionInfo());

        $this->metrics->expects($this->once())->method('decrementActiveConnections');

        $this->manager->removeClient($client->getId());

        $this->assertSame(0, $this->manager->getClientCount());
    }

    public function testRemoveNonExistentClientIsNoOp(): void
    {
        $this->metrics->expects($this->never())->method('decrementActiveConnections');

        $this->manager->removeClient(ClientId::fromString('missing'));

        $this->assertSame(0, $this->manager->getClientCount());
    }

    public function testGetClientReturnsRegisteredClient(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $client = $this->manager->registerClient($connection, $this->createConnectionInfo());

        $found = $this->manager->getClient($client->getId());

        $this->assertNotNull($found);
        $this->assertTrue($client->getId()->equals($found->getId()));
    }

    public function testGetClientReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->manager->getClient(ClientId::fromString('unknown')));
    }

    public function testGetAllClientsReturnsCollection(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $this->manager->registerClient($connection, $this->createConnectionInfo());
        $this->manager->registerClient($connection, $this->createConnectionInfo());

        $all = $this->manager->getAllClients();

        $this->assertSame(2, $all->count());
    }
}
