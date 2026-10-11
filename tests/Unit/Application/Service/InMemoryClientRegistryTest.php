<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\SessionCounters;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InMemoryClientRegistryTest extends TestCase
{
    private InMemoryClientRegistry $manager;

    protected function setUp(): void
    {
        $this->manager = $this->makeManager();
    }

    private function makeManager(?MetricsCollectorInterface $metrics = null): InMemoryClientRegistry
    {
        return new InMemoryClientRegistry(
            $metrics ?? $this->createStub(MetricsCollectorInterface::class),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );
    }

    private function createConnectionInfo(): ConnectionInfo
    {
        return new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now());
    }

    public function testRegisterClientIncrementsActiveConnections(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementActiveConnections');

        $this->makeManager($metrics)->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());
    }

    public function testRegisterClientReturnsRelayClient(): void
    {
        $connectionInfo = $this->createConnectionInfo();

        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $connectionInfo);

        $this->assertSame($connectionInfo, $client->getConnectionInfo());
    }

    public function testRegisterClientCountsTheClient(): void
    {
        $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $this->assertSame(1, $this->manager->getClientCount());
    }

    public function testRemoveClientDecrementsActiveConnections(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('decrementActiveConnections');
        $manager = $this->makeManager($metrics);

        $client = $manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $manager->removeClient($client->getId());
    }

    public function testRemoveClientDecrementsCount(): void
    {
        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $this->manager->removeClient($client->getId());

        $this->assertSame(0, $this->manager->getClientCount());
    }

    public function testRemoveNonExistentClientLeavesActiveConnectionsAlone(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('decrementActiveConnections');

        $this->makeManager($metrics)->removeClient(ClientId::fromString('missing'));
    }

    public function testRemoveNonExistentClientIsNoOp(): void
    {
        $this->manager->removeClient(ClientId::fromString('missing'));

        $this->assertSame(0, $this->manager->getClientCount());
    }

    public function testGetClientReturnsRegisteredClient(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $client = $this->manager->registerClient($connection, $this->createConnectionInfo());

        $found = $this->manager->getClient($client->getId());

        $this->assertEquals($client->getId(), $found?->getId());
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

        $this->assertEquals(SessionCounters::empty(), $this->manager->getSessionCounters($client->getId()));
    }

    public function testRecordEventReceivedUpdatesItsCounter(): void
    {
        $this->assertSame(2, $this->countersAfterTwoReceiptsAndOneAcceptance()->getEventsReceived());
    }

    public function testRecordEventAcceptedUpdatesItsCounter(): void
    {
        $this->assertSame(1, $this->countersAfterTwoReceiptsAndOneAcceptance()->getEventsAccepted());
    }

    public function testRecordEventSentUpdatesCounter(): void
    {
        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $this->manager->recordEventSent($client->getId());

        $this->assertSame(1, $this->manager->getSessionCounters($client->getId())->getEventsSent());
    }

    private function countersAfterTwoReceiptsAndOneAcceptance(): SessionCounters
    {
        $client = $this->manager->registerClient($this->createStub(ClientConnectionInterface::class), $this->createConnectionInfo());

        $this->manager->recordEventReceived($client->getId());
        $this->manager->recordEventReceived($client->getId());
        $this->manager->recordEventAccepted($client->getId());

        return $this->manager->getSessionCounters($client->getId());
    }
}
