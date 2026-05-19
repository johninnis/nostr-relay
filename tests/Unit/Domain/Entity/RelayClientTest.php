<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Entity;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\SubscriptionCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RelayClientTest extends TestCase
{
    private ClientId $clientId;
    private ConnectionInfo $connectionInfo;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->clientId = ClientId::fromString('test-client');
        $this->connectionInfo = new ConnectionInfo('127.0.0.1', 'TestAgent/1.0', Timestamp::now());
        $this->client = $this->makeClient();
    }

    private function makeClient(
        ?ClientConnectionInterface $connection = null,
        ?SubscriptionLookupInterface $subscriptionLookup = null,
    ): RelayClient {
        return new RelayClient(
            $this->clientId,
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            $this->connectionInfo,
            $subscriptionLookup ?? $this->createStub(SubscriptionLookupInterface::class),
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
        $subscriptionLookup = $this->createMock(SubscriptionLookupInterface::class);
        $subscriptionLookup
            ->expects($this->once())
            ->method('getSubscriptionsForClient')
            ->with($this->clientId)
            ->willReturn($collection);
        $client = $this->makeClient(subscriptionLookup: $subscriptionLookup);

        $result = $client->getSubscriptions();

        $this->assertSame($collection, $result);
    }

    public function testGetSubscriptionCountDelegatesToLookup(): void
    {
        $subscriptionLookup = $this->createMock(SubscriptionLookupInterface::class);
        $subscriptionLookup
            ->expects($this->once())
            ->method('getSubscriptionCountForClient')
            ->with($this->clientId)
            ->willReturn(3);
        $client = $this->makeClient(subscriptionLookup: $subscriptionLookup);

        $this->assertSame(3, $client->getSubscriptionCount());
    }

    public function testSendDelegatesToConnection(): void
    {
        $message = new NoticeMessage('hello');

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection
            ->expects($this->once())
            ->method('sendText')
            ->with($message->toJson());
        $client = $this->makeClient($connection);

        $client->send($message);
    }

    public function testSessionCountersStartEmpty(): void
    {
        $counters = $this->client->getSessionCounters();

        $this->assertSame(0, $counters->getEventsReceived());
        $this->assertSame(0, $counters->getEventsAccepted());
        $this->assertSame(0, $counters->getEventsSent());
    }

    public function testRecordEventReceivedUpdatesCounters(): void
    {
        $this->client->recordEventReceived();
        $this->client->recordEventReceived();

        $this->assertSame(2, $this->client->getSessionCounters()->getEventsReceived());
    }

    public function testRecordEventAcceptedUpdatesCounters(): void
    {
        $this->client->recordEventAccepted();
        $this->client->recordEventAccepted();
        $this->client->recordEventAccepted();

        $this->assertSame(3, $this->client->getSessionCounters()->getEventsAccepted());
    }

    public function testSendingEventMessageIncrementsEventsSent(): void
    {
        $message = new EventMessage(SubscriptionId::fromString('sub-1'), $this->createEvent());

        $this->client->send($message);
        $this->client->send($message);

        $this->assertSame(2, $this->client->getSessionCounters()->getEventsSent());
    }

    public function testSendingNonEventMessageDoesNotIncrementEventsSent(): void
    {
        $this->client->send(new NoticeMessage('hi'));

        $this->assertSame(0, $this->client->getSessionCounters()->getEventsSent());
    }

    public function testFailedSendDoesNotIncrementEventsSent(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')
            ->willThrowException(ConnectionException::peerDisconnected());
        $client = $this->makeClient($connection);

        $message = new EventMessage(SubscriptionId::fromString('sub-1'), $this->createEvent());

        $this->expectException(ConnectionException::class);

        try {
            $client->send($message);
        } finally {
            $this->assertSame(0, $client->getSessionCounters()->getEventsSent());
        }
    }

    public function testCloseDelegatesToConnection(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection
            ->expects($this->once())
            ->method('close');
        $client = $this->makeClient($connection);

        $client->close();
    }

    private function createEvent(): Event
    {
        $pubkey = PublicKey::fromHex(str_repeat('a', 64))
            ?? throw new RuntimeException('invalid test pubkey');

        return new Event(
            $pubkey,
            Timestamp::now(),
            EventKind::textNote(),
            TagCollection::empty(),
            EventContent::fromString('test'),
        );
    }
}
