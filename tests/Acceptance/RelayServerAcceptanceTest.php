<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Acceptance;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\EventLimits;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage as ClientEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage as RelayEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function Amp\Websocket\Client\connect;

final class RelayServerAcceptanceTest extends TestCase
{
    private ?SocketHttpServer $httpServer = null;
    private ?SignatureServiceInterface $signer = null;

    protected function tearDown(): void
    {
        $this->httpServer?->stop();
        $this->httpServer = null;
    }

    public function testSignedEventIsAccepted(): void
    {
        self::assertTrue($this->answerToPublishing($this->signedTextNote('hello from acceptance'))?->isAccepted());
    }

    public function testTheAcceptanceNamesTheSignedEvent(): void
    {
        $event = $this->signedTextNote('hello from acceptance');

        self::assertSame($event->getId()->toHex(), $this->answerToPublishing($event)?->getEventId()->toHex());
    }

    public function testAnAcceptedEventIsStreamedBackToASubscriberAsAnEventFrame(): void
    {
        [$eventFrame] = $this->framesStreamedToASubscriberAfterPublishing($this->signedTextNote('hello from acceptance'));

        self::assertSame('EVENT', $this->decodeFrame($eventFrame)[0]);
    }

    public function testTheStreamedEventFrameCarriesTheSubscriptionId(): void
    {
        [$eventFrame] = $this->framesStreamedToASubscriberAfterPublishing($this->signedTextNote('hello from acceptance'));

        self::assertSame('acc-sub', $this->decodeFrame($eventFrame)[1]);
    }

    public function testTheStreamedEventIsTheOneThatWasPublished(): void
    {
        $event = $this->signedTextNote('hello from acceptance');

        [$eventFrame] = $this->framesStreamedToASubscriberAfterPublishing($event);

        self::assertSame($event->getId()->toHex(), RelayEventMessage::tryFromJson($eventFrame)?->getEvent()->getId()->toHex());
    }

    public function testTheStoredEventsAreFollowedByAnEndOfStoredEventsFrame(): void
    {
        [, $endOfStoredEventsFrame] = $this->framesStreamedToASubscriberAfterPublishing($this->signedTextNote('hello from acceptance'));

        self::assertSame('EOSE', $this->decodeFrame($endOfStoredEventsFrame)[0]);
    }

    public function testTheEndOfStoredEventsFrameCarriesTheSubscriptionId(): void
    {
        [, $endOfStoredEventsFrame] = $this->framesStreamedToASubscriberAfterPublishing($this->signedTextNote('hello from acceptance'));

        self::assertSame('acc-sub', $this->decodeFrame($endOfStoredEventsFrame)[1]);
    }

    public function testRefusesAnEventLongerThanTheConfiguredContentLength(): void
    {
        $answer = $this->answerToPublishing($this->signedTextNote('too long'), new EventLimits(maxContentLength: 5));

        self::assertFalse($answer?->isAccepted());
    }

    public function testRefusesAnEventLongerThanTheConfiguredContentLengthAsInvalid(): void
    {
        $answer = $this->answerToPublishing($this->signedTextNote('too long'), new EventLimits(maxContentLength: 5));

        self::assertSame('invalid: Event content exceeds maximum length', $answer?->getMessage());
    }

    public function testServesNip11DocumentOverHttp(): void
    {
        self::assertSame(200, $this->nip11Response()->getStatus());
    }

    public function testServesTheNip11DocumentAsNostrJson(): void
    {
        self::assertStringContainsString('application/nostr+json', $this->nip11Response()->getHeader('content-type') ?? '');
    }

    public function testTheNip11DocumentCarriesTheConfiguredName(): void
    {
        self::assertSame('Acceptance Relay', $this->nip11Document()['name'] ?? null);
    }

    private function answerToPublishing(Event $event, EventLimits $eventLimits = new EventLimits()): ?OkMessage
    {
        $connection = connect("ws://{$this->startRelay($eventLimits)}/");
        $connection->sendText(new ClientEventMessage($event)->toJson());

        $answer = OkMessage::tryFromJson($this->nextFrame($connection));
        $connection->close();

        return $answer;
    }

    /**
     * @return list<string>
     */
    private function framesStreamedToASubscriberAfterPublishing(Event $event): array
    {
        $connection = connect("ws://{$this->startRelay()}/");
        $connection->sendText(new ClientEventMessage($event)->toJson());
        $this->nextFrame($connection);

        $subscriptionId = SubscriptionId::tryFromString('acc-sub') ?? throw new RuntimeException('bad subscription id');
        $connection->sendText(ReqMessage::from($subscriptionId, new FilterCollection([Filter::from()]))->toJson());

        $frames = [$this->nextFrame($connection), $this->nextFrame($connection)];
        $connection->close();

        return $frames;
    }

    private function nip11Response(): Response
    {
        $request = new Request("http://{$this->startRelay()}/");
        $request->setHeader('accept', 'application/nostr+json');

        return HttpClientBuilder::buildDefault()->request($request, new TimeoutCancellation(5));
    }

    /**
     * @return array<mixed>
     */
    private function nip11Document(): array
    {
        $document = json_decode($this->nip11Response()->getBody()->buffer(new TimeoutCancellation(5)), true);

        return is_array($document) ? $document : throw new RuntimeException('the NIP-11 document is not a JSON object');
    }

    private function startRelay(EventLimits $eventLimits = new EventLimits()): string
    {
        $relayUrl = RelayUrl::fromString('ws://127.0.0.1:8080');

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getMaxConnections')->willReturn(64);
        $config->method('getRelayUrl')->willReturn($relayUrl);
        $config->method('getEventLimits')->willReturn($eventLimits);

        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $factory = new RelayServerFactory(
            eventStore: new InMemoryEventStore(),
            policy: new RelayPolicy($authenticationRegistry, new NullLogger(), RelayPolicyConfig::tryFromArray([]) ?? self::fail('config did not parse')),
            config: $config,
        );

        $factory = $factory
            ->withRateLimitPolicy(new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 1000, subscriptionsPerMinute: 1000)))
            ->withAuthenticationRegistry($authenticationRegistry)
            ->withLogger(new NullLogger())
            ->withNip11InfoProvider(new StaticNip11InfoProvider(Nip11Info::fromArray($relayUrl, [
                'name' => 'Acceptance Relay',
                'supported_nips' => [1, 11],
            ])));

        $httpServer = SocketHttpServer::createForDirectAccess(new NullLogger());
        $httpServer->expose(new InternetAddress('127.0.0.1', 0));

        $relay = $factory->create($httpServer);
        $httpServer->start($relay->getRequestHandler(), new DefaultErrorHandler());
        $this->httpServer = $httpServer;

        $address = $httpServer->getServers()[0] ?? throw new RuntimeException('relay is not listening');

        return (string) $address->getAddress();
    }

    private function nextFrame(WebsocketConnection $connection): string
    {
        $message = $connection->receive(new TimeoutCancellation(5));

        return ($message ?? throw new RuntimeException('connection closed before a frame arrived'))->buffer(new TimeoutCancellation(5));
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeFrame(string $frame): array
    {
        $decoded = json_decode($frame, true);

        return is_array($decoded) ? array_values($decoded) : throw new RuntimeException('frame is not a JSON array');
    }

    private function signedTextNote(string $content): Event
    {
        $keyPair = KeyPair::generate($this->signer());

        return Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString($content),
            new TagCollection(),
        )->sign($keyPair, $this->signer());
    }

    private function signer(): SignatureServiceInterface
    {
        return $this->signer ??= Secp256k1Signer::create();
    }
}
