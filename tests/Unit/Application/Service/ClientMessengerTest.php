<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientMessengerTest extends TestCase
{
    private InMemoryClientRegistry $registry;
    private ClientMessenger $messenger;

    protected function setUp(): void
    {
        $this->registry = new InMemoryClientRegistry(
            $this->createStub(MetricsCollectorInterface::class),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );
        $this->messenger = new ClientMessenger($this->registry);
    }

    private function register(ClientConnectionInterface $connection): RelayClient
    {
        return $this->registry->registerClient(
            $connection,
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testDeliversMessageToTheClientConnection(): void
    {
        $message = new NoticeMessage('hello');
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')->with($message->toJson());

        $this->messenger->send($this->register($connection), $message);
    }

    public function testSendToUnknownClientIsNoOp(): void
    {
        $client = $this->register($this->createStub(ClientConnectionInterface::class));
        $this->registry->removeClient($client->getId());

        $this->expectNotToPerformAssertions();

        $this->messenger->send($client, new NoticeMessage('hello'));
    }

    public function testSendingEventMessageIncrementsEventsSent(): void
    {
        $client = $this->register($this->createStub(ClientConnectionInterface::class));
        $message = new EventMessage(SubscriptionIdMother::from('sub-1'), $this->createEvent());

        $this->messenger->send($client, $message);
        $this->messenger->send($client, $message);

        $this->assertSame(2, $this->registry->getSessionCounters($client->getId())->getEventsSent());
    }

    public function testSendingNonEventMessageDoesNotIncrementEventsSent(): void
    {
        $client = $this->register($this->createStub(ClientConnectionInterface::class));

        $this->messenger->send($client, new NoticeMessage('hi'));

        $this->assertSame(0, $this->registry->getSessionCounters($client->getId())->getEventsSent());
    }

    public function testFailedSendDoesNotIncrementEventsSent(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willThrowException(ConnectionException::peerDisconnected());
        $client = $this->register($connection);
        $message = new EventMessage(SubscriptionIdMother::from('sub-1'), $this->createEvent());

        $this->expectException(ConnectionException::class);

        try {
            $this->messenger->send($client, $message);
        } finally {
            $this->assertSame(0, $this->registry->getSessionCounters($client->getId())->getEventsSent());
        }
    }

    private function createEvent(): Event
    {
        return EventMother::fromRumour(new Rumour(
            KeyMother::alicePublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString('test'),
        ));
    }
}
