<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage as RelayCountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPublisher;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\ClientMessageDispatcher;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class MessageRouterTest extends TestCase
{
    private function signatureService(): \Innis\Nostr\Core\Domain\Service\SignatureServiceInterface
    {
        return Secp256k1Signer::create();
    }

    private MessageDeserialiserInterface&Stub $deserialiser;
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private InMemorySubscriptionRegistry $subscriptionManager;
    private InMemoryAuthenticationRegistry $authManager;
    private InMemoryClientRegistry $clientManager;
    private MessageRouter $router;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->deserialiser = $this->createStub(MessageDeserialiserInterface::class);
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->policy->method('allowsAuthentication')->willReturn(true);
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->authManager = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $this->clientManager = new InMemoryClientRegistry(
            $metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );
        $messenger = new ClientMessenger($this->clientManager);

        $distributor = new EventDistributor(
            $this->policy,
            $this->subscriptionManager,
            $this->clientManager,
            $messenger,
            $logger,
        );

        $signatureService = $this->signatureService();
        $eventValidator = new EventValidator($signatureService, new NipComplianceValidator($signatureService));

        $admission = new SubscriptionAdmission($this->policy, $rateLimiter, $this->authManager, $messenger, $this->subscriptionManager);

        $eventAdmission = new EventAdmission($this->policy, $rateLimiter, $eventValidator);

        $acceptedEventPublisher = new AcceptedEventPublisher(
            $this->clientManager,
            $distributor,
            new AmphpDeferredExecutor(),
        );

        $acceptedEventPipeline = new AcceptedEventPipeline(
            $this->eventStore,
            $acceptedEventPublisher,
            new EventDeletionProcessor($this->eventStore, $logger),
            $logger,
        );

        $processEvent = new ProcessEventSubmissionUseCase(
            $eventAdmission,
            $acceptedEventPipeline,
            $this->authManager,
            $this->clientManager,
            $logger,
        );

        $storedEventStreamer = new StoredEventStreamer(
            $this->eventStore,
            $this->policy,
            $messenger,
            $this->subscriptionManager,
            $logger,
        );

        $createSubscription = new CreateSubscriptionUseCase(
            $admission,
            $this->subscriptionManager,
            $storedEventStreamer,
            new AmphpDeferredExecutor(),
            $logger,
        );

        $closeSubscription = new CloseSubscriptionUseCase($this->subscriptionManager, $logger);

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $processAuth = new ProcessAuthUseCase(
            $this->authManager,
            new AuthEventVerifier($config, $this->policy, new SystemClock()),
            $eventValidator,
            new SubscriptionReevaluator($this->subscriptionManager, $createSubscription, $messenger),
            $logger,
        );

        $countSubscription = new CountSubscriptionUseCase(
            $this->eventStore,
            $admission,
            $logger,
        );

        $dispatcher = new ClientMessageDispatcher(
            $this->deserialiser,
            $logger,
            $processEvent,
            $createSubscription,
            $closeSubscription,
            $processAuth,
            $countSubscription,
        );

        $this->router = new MessageRouter($dispatcher, $messenger, $logger);

        $this->client = $this->makeClient();
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientManager->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testRoutesEventMessage(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = (new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString('test'),
        ))->sign($keyPair, $this->signatureService());

        $this->deserialiser->method('deserialiseClientMessage')->willReturn(new EventMessage($event));
        $this->eventStore->method('store')->willReturn(EventStoreOutcome::Stored);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, '["EVENT",{}]');
    }

    public function testRoutesReqMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->deserialiser->method('deserialiseClientMessage')->willReturn(new ReqMessage($subId, $filters));
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $this->router->route($this->client, '["REQ","sub-1",{}]');

        $clientId = $this->client->getId();
        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($clientId));
    }

    public function testRoutesCloseMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->deserialiser->method('deserialiseClientMessage')
            ->willReturnOnConsecutiveCalls(
                new ReqMessage($subId, $filters),
                new CloseMessage($subId),
            );
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $this->router->route($this->client, '["REQ","sub-1",{}]');
        $this->router->route($this->client, '["CLOSE","sub-1"]');

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testRoutesAuthMessage(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));
        $client = $this->makeClient($connection);
        $challenge = $this->authManager->getOrCreateChallenge($client->getId());

        $event = (new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            new TagCollection([
                Tag::fromArray(['relay', 'wss://relay.example.com']),
                Tag::fromArray(['challenge', $challenge]),
            ]),
            EventContent::fromString(''),
        ))->sign($keyPair, $this->signatureService());

        $this->deserialiser->method('deserialiseClientMessage')->willReturn(new AuthMessage($event));

        $this->router->route($client, '["AUTH",{}]');

        $this->assertTrue($this->authManager->isAuthenticated($client->getId()));
    }

    public function testRoutesCountMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([new Filter()]);

        $this->deserialiser->method('deserialiseClientMessage')->willReturn(new CountMessage($subId, $filters));
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(42);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = RelayCountMessage::fromJson($json);

                return null !== $message && 'count-1' === (string) $message->getSubscriptionId() && 42 === $message->getCount();
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, '["COUNT","count-1",{}]');
    }

    public function testSendsNoticeForInvalidMessage(): void
    {
        $this->deserialiser
            ->method('deserialiseClientMessage')
            ->willThrowException(new InvalidArgumentException('bad json'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = NoticeMessage::fromJson($json);

                return null !== $message && str_contains($message->getMessage(), 'Invalid message');
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, 'invalid');
    }

    public function testSendsNoticeForUnexpectedError(): void
    {
        $this->deserialiser
            ->method('deserialiseClientMessage')
            ->willThrowException(new RuntimeException('unexpected'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = NoticeMessage::fromJson($json);

                return null !== $message && str_contains($message->getMessage(), 'Internal server error');
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, '[]');
    }
}
