<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Entity;

use Innis\Nostr\Core\Domain\Entity\SubscriptionCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RelayClientTest extends TestCase
{
    private ClientId $clientId;
    private ClientConnectionInterface&MockObject $connection;
    private ConnectionInfo $connectionInfo;
    private SubscriptionLookupInterface&MockObject $subscriptionLookup;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->clientId = ClientId::fromString('test-client');
        $this->connection = $this->createMock(ClientConnectionInterface::class);
        $this->connectionInfo = new ConnectionInfo('127.0.0.1', 'TestAgent/1.0', Timestamp::now());
        $this->subscriptionLookup = $this->createMock(SubscriptionLookupInterface::class);

        $this->client = new RelayClient(
            $this->clientId,
            $this->connection,
            $this->connectionInfo,
            $this->subscriptionLookup,
        );
    }

    public function testGetIdReturnsClientId(): void
    {
        $this->assertTrue($this->clientId->equals($this->client->getId()));
    }

    public function testGetConnectionInfoReturnsConnectionInfo(): void
    {
        $this->assertSame($this->connectionInfo, $this->client->getConnectionInfo());
    }

    public function testGetSubscriptionsDelegatesToLookup(): void
    {
        $collection = SubscriptionCollection::empty();
        $this->subscriptionLookup
            ->expects($this->once())
            ->method('getSubscriptionsForClient')
            ->with($this->clientId)
            ->willReturn($collection);

        $result = $this->client->getSubscriptions();

        $this->assertSame($collection, $result);
    }

    public function testGetSubscriptionCountDelegatesToLookup(): void
    {
        $this->subscriptionLookup
            ->expects($this->once())
            ->method('getSubscriptionCountForClient')
            ->with($this->clientId)
            ->willReturn(3);

        $this->assertSame(3, $this->client->getSubscriptionCount());
    }

    public function testSendDelegatesToConnection(): void
    {
        $message = new \Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage('hello');

        $this->connection
            ->expects($this->once())
            ->method('sendText')
            ->with($message->toJson());

        $this->client->send($message);
    }

    public function testCloseDelegatesToConnection(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('close');

        $this->client->close();
    }
}
