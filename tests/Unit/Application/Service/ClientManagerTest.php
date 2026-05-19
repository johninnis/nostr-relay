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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientManagerTest extends TestCase
{
    private SubscriptionLookupInterface&Stub $subscriptionLookup;
    private ClientManager $manager;

    protected function setUp(): void
    {
        $this->subscriptionLookup = $this->createStub(SubscriptionLookupInterface::class);
        $this->manager = $this->makeManager();
    }

    private function makeManager(?MetricsCollectorInterface $metrics = null): ClientManager
    {
        return new ClientManager(
            $this->subscriptionLookup,
            $metrics ?? $this->createStub(MetricsCollectorInterface::class),
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

    public function testGetAllClientsReturnsCollection(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $this->manager->registerClient($connection, $this->createConnectionInfo());
        $this->manager->registerClient($connection, $this->createConnectionInfo());

        $all = $this->manager->getAllClients();

        $this->assertSame(2, $all->count());
    }
}
