<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Acceptance;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
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
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage as ClientEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Innis\Nostr\Relay\Tests\Support\InMemoryEventStore;
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

    public function testSignedEventIsAcceptedAndStreamedBackToASubscriber(): void
    {
        $base = $this->startRelay();
        $connection = connect("ws://{$base}/");

        $event = $this->signedTextNote('hello from acceptance');
        $connection->sendText(new ClientEventMessage($event)->toJson());

        $ok = OkMessage::tryFromJson($this->nextFrame($connection));
        self::assertNotNull($ok);
        self::assertTrue($ok->isAccepted(), 'relay should accept a valid signed event');
        self::assertSame($event->getId()->toHex(), $ok->getEventId()->toHex());

        $subscriptionId = SubscriptionId::tryFromString('acc-sub') ?? throw new RuntimeException('bad subscription id');
        $connection->sendText(new ReqMessage($subscriptionId, new FilterCollection([new Filter()]))->toJson());

        $eventFrame = $this->decodeFrame($this->nextFrame($connection));
        self::assertSame('EVENT', $eventFrame[0]);
        self::assertSame('acc-sub', $eventFrame[1]);
        self::assertIsArray($eventFrame[2]);
        self::assertSame($event->getId()->toHex(), $eventFrame[2]['id']);

        $eoseFrame = $this->decodeFrame($this->nextFrame($connection));
        self::assertSame('EOSE', $eoseFrame[0]);
        self::assertSame('acc-sub', $eoseFrame[1]);

        $connection->close();
    }

    public function testServesNip11DocumentOverHttp(): void
    {
        $base = $this->startRelay();

        $request = new Request("http://{$base}/");
        $request->setHeader('accept', 'application/nostr+json');
        $response = HttpClientBuilder::buildDefault()->request($request, new TimeoutCancellation(5));

        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('application/nostr+json', $response->getHeader('content-type') ?? '');

        $document = json_decode($response->getBody()->buffer(new TimeoutCancellation(5)), true);
        self::assertIsArray($document);
        self::assertSame('Acceptance Relay', $document['name']);
    }

    private function startRelay(): string
    {
        $relayUrl = RelayUrl::tryFromString('ws://127.0.0.1:8080') ?? throw new RuntimeException('bad relay url');

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getMaxConnections')->willReturn(64);
        $config->method('getRelayUrl')->willReturn($relayUrl);

        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $factory = new RelayServerFactory(
            eventStore: new InMemoryEventStore(),
            policy: new RelayPolicy($authenticationRegistry, new NullLogger(), RelayPolicyConfig::tryFromArray([]) ?? self::fail('config did not parse')),
            config: $config,
            rateLimitPolicy: new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 1000, subscriptionsPerMinute: 1000)),
            authenticationRegistry: $authenticationRegistry,
            logger: new NullLogger(),
            nip11InfoProvider: new StaticNip11InfoProvider(Nip11Info::fromArray($relayUrl, [
                'name' => 'Acceptance Relay',
                'supported_nips' => [1, 11],
            ])),
        );

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
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    private function signedTextNote(string $content): Event
    {
        $keyPair = KeyPair::generate($this->signer());

        return new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString($content),
        )->sign($keyPair, $this->signer());
    }

    private function signer(): SignatureServiceInterface
    {
        return $this->signer ??= Secp256k1Signer::create();
    }
}
