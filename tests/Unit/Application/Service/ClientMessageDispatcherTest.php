<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientMessageDispatcher;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlers;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ClientMessageDispatcherTest extends TestCase
{
    private InMemoryClientRegistry $clientRegistry;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->clientRegistry = new InMemoryClientRegistry(
            $this->createStub(MetricsCollectorInterface::class),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );
        $this->client = $this->clientRegistry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testAnEventMessageIsRecordedAsReceived(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsReceived');

        $clientRegistry = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), new NullLogger());
        $client = $clientRegistry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $this->dispatcher($this->handlerReturning(EventMessage::class, []), $clientRegistry)
            ->dispatch($client, new EventMessage($this->event())->toJson());
    }

    public function testAnUnknownMessageTypeIsAnsweredWithANotice(): void
    {
        $replies = $this->dispatcher()
            ->dispatch($this->client, ReqMessage::from(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]))->toJson());

        $this->assertEquals([NoticeMessage::fromString('Unknown message type')], $replies);
    }

    public function testAFailingEventHandlerIsFramedAsAnOkError(): void
    {
        $event = $this->event();

        $replies = $this->dispatcher($this->failingHandler(EventMessage::class))
            ->dispatch($this->client, new EventMessage($event)->toJson());

        $this->assertEquals([OkMessage::refused($event->getId(), ReasonPrefix::Error, 'could not process event')], $replies);
    }

    public function testAFailingAuthHandlerIsFramedAsAnOkError(): void
    {
        $authEvent = $this->authEvent();

        $replies = $this->dispatcher($this->failingHandler(AuthMessage::class))
            ->dispatch($this->client, AuthMessage::fromEvent($authEvent)->toJson());

        $this->assertEquals([OkMessage::refused($authEvent->getId(), ReasonPrefix::Error, 'could not process authentication')], $replies);
    }

    public function testAFailingReqHandlerIsFramedAsAClosedError(): void
    {
        $replies = $this->dispatcher($this->failingHandler(ReqMessage::class))
            ->dispatch($this->client, ReqMessage::from(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]))->toJson());

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('sub-1'), ReasonPrefix::Error, 'could not process subscription')], $replies);
    }

    public function testAFailingCountHandlerIsFramedAsAClosedError(): void
    {
        $replies = $this->dispatcher($this->failingHandler(CountMessage::class))
            ->dispatch($this->client, CountMessage::from(SubscriptionIdMother::from('count-1'), new FilterCollection([Filter::from()]))->toJson());

        $this->assertEquals([ClosedMessage::closed(SubscriptionIdMother::from('count-1'), ReasonPrefix::Error, 'could not count events')], $replies);
    }

    public function testAFailingCloseHandlerIsAnsweredWithSilence(): void
    {
        $replies = $this->dispatcher($this->failingHandler(CloseMessage::class))
            ->dispatch($this->client, new CloseMessage(SubscriptionIdMother::from('sub-1'))->toJson());

        $this->assertSame([], $replies);
    }

    private function dispatcher(?ClientVerbHandlerInterface $handler = null, ?InMemoryClientRegistry $clientRegistry = null): ClientMessageDispatcher
    {
        return new ClientMessageDispatcher(
            new ClientVerbHandlers(...null === $handler ? [] : [$handler]),
            $clientRegistry ?? $this->clientRegistry,
            new NullLogger(),
        );
    }

    /**
     * @param class-string<ClientMessage> $messageType
     * @param list<RelayMessage>          $replies
     */
    private function handlerReturning(string $messageType, array $replies): ClientVerbHandlerInterface
    {
        $handler = $this->createStub(ClientVerbHandlerInterface::class);
        $handler->method('handledMessageType')->willReturn($messageType);
        $handler->method('handle')->willReturn($replies);

        return $handler;
    }

    /**
     * @param class-string<ClientMessage> $messageType
     */
    private function failingHandler(string $messageType): ClientVerbHandlerInterface
    {
        $handler = $this->createStub(ClientVerbHandlerInterface::class);
        $handler->method('handledMessageType')->willReturn($messageType);
        $handler->method('handle')->willThrowException(new RuntimeException('boom'));

        return $handler;
    }

    private function authEvent(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection(),
        ));
    }

    private function event(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection(),
        ));
    }
}
