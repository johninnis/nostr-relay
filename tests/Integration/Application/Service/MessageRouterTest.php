<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\Service;

use Amp\DeferredFuture;
use Innis\Nostr\Core\Application\Service\Nip42Validator;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\Nip42EventChecker;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
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
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\ClientMessageDispatcher;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlers;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\EventAcceptance;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventAudience;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Application\Service\HeldSubscriptionSender;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\Nip42Handshake;
use Innis\Nostr\Relay\Application\Service\PublishingGate;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\ReadScopeGate;
use Innis\Nostr\Relay\Application\Service\RegisteringStoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientConnection;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use Innis\Nostr\Relay\Tests\Support\TestDoubleException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function Amp\delay;

final class MessageRouterTest extends TestCase
{
    private function signatureService(): \Innis\Nostr\Core\Domain\Service\SignatureServiceInterface
    {
        return Secp256k1Signer::create();
    }

    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private InMemoryClientRegistry $clientRegistry;
    private MessageRouter $router;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->policy->method('allowsAuthentication')->willReturn(null);
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(true);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $this->clientRegistry = new InMemoryClientRegistry(
            $metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );
        $messenger = new ClientMessenger($this->clientRegistry);

        $distributor = new EventDistributor(
            new EventAudience(new DeliveryGate($this->policy), $this->subscriptionRegistry, $this->clientRegistry),
            $messenger,
            $logger,
        );

        $signatureService = $this->signatureService();
        $eventValidator = new EventValidator($signatureService, new NipComplianceValidator($signatureService));
        $validityGate = new EventValidityGate($eventValidator, new SystemClock());

        $authChallengeIssuer = new AuthChallengeIssuer($this->authenticationRegistry);

        $rateLimitGate = new RateLimitGate($rateLimiter, $this->policy);

        $admission = new SubscriptionAdmission(
            $rateLimitGate,
            new ReadScopeGate($this->policy, $this->authenticationRegistry),
            $this->subscriptionRegistry,
        );

        $eventAdmission = new EventAdmission(
            $rateLimitGate,
            $validityGate,
            new PublishingGate($this->policy, $this->authenticationRegistry, $authChallengeIssuer),
        );

        $acceptedEventPipeline = new AcceptedEventPipeline(
            $this->eventStore,
            new AcceptedEventPublisher(
                $this->clientRegistry,
                $distributor,
                new AmphpDeferredExecutor(),
            ),
            new EventDeletionProcessor($this->eventStore, $logger),
        );

        $subscriptionAnswers = new SubscriptionAnswers($this->subscriptionRegistry, $authChallengeIssuer, $logger);

        $storedEventStreamer = new StoredEventStreamer(
            new StoredEventReadGate($this->eventStore, new DeliveryGate($this->policy), new SystemClock()),
            new HeldSubscriptionSender($messenger, $this->subscriptionRegistry, $subscriptionAnswers),
        );

        $subscriptionActivator = new SubscriptionActivator(
            $admission,
            new RegisteringStoredEventStreamer(new AmphpDeferredExecutor(), $storedEventStreamer, $this->subscriptionRegistry),
            $subscriptionAnswers,
        );

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $dispatcher = new ClientMessageDispatcher(
            new ClientVerbHandlers(
                new ProcessEventSubmissionUseCase($eventAdmission, new EventAcceptance($acceptedEventPipeline, $authChallengeIssuer), $logger),
                new CreateSubscriptionUseCase($subscriptionActivator),
                new CloseSubscriptionUseCase($this->subscriptionRegistry),
                new ProcessAuthUseCase(
                    new Nip42Handshake(
                        $this->authenticationRegistry,
                        new AuthEventVerifier($config, $this->policy, new Nip42Validator(new Nip42EventChecker(), new SystemClock())),
                        $authChallengeIssuer,
                    ),
                    $validityGate,
                ),
                new CountSubscriptionUseCase($this->eventStore, $admission, $subscriptionAnswers),
            ),
            $this->clientRegistry,
            $logger,
        );

        $this->router = new MessageRouter($dispatcher, $messenger, $logger);

        $this->client = $this->makeClient();
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientRegistry->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testRoutesEventMessage(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('test'),
            new TagCollection(),
        )->sign($keyPair, $this->signatureService());

        $this->eventStore->method('store')->willReturn(EventStoreOutcome::Stored);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, new EventMessage($event)->toJson());
    }

    public function testRoutesReqMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));

        $this->router->route($this->client, ReqMessage::from($subId, $filters)->toJson());

        $clientId = $this->client->getId();
        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($clientId));
    }

    public function testRoutesCloseMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([Filter::from()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new StoredEventCollection([]));

        $this->router->route($this->client, ReqMessage::from($subId, $filters)->toJson());
        $this->router->route($this->client, new CloseMessage($subId)->toJson());

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testRoutesAuthMessage(): void
    {
        $client = $this->routeAValidAuthMessage($this->createStub(ClientConnectionInterface::class));

        $this->assertTrue($this->authenticationRegistry->isAuthenticated($client->getId()));
    }

    public function testAnAuthenticatingAuthMessageIsAnsweredWithAnAcceptingOk(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));

        $this->routeAValidAuthMessage($connection);
    }

    private function routeAValidAuthMessage(ClientConnectionInterface $connection): RelayClient
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $client = $this->makeClient($connection);
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($client->getId());

        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection([
                Tag::tryFromArray(['relay', 'wss://relay.example.com']),
                Tag::tryFromArray(['challenge', (string) $challenge]),
            ]),
        )->sign($keyPair, $this->signatureService());

        $this->router->route($client, AuthMessage::fromEvent($event)->toJson());

        return $client;
    }

    public function testRoutesCountMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([EventKind::TEXT_NOTE]))]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(EventCount::exact(42));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = RelayCountMessage::tryFromJson($json);

                return null !== $message && 'count-1' === (string) $message->getSubscriptionId() && 42 === $message->getCount()->toInt();
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, CountMessage::from($subId, $filters)->toJson());
    }

    public function testAReqWhoseFiltersCannotMatchIsAnsweredWithEoseAndNoEvents(): void
    {
        $this->routeAgainstAStoreHoldingOneNote();
        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $sent[] = $json;
        });

        $this->router->route($this->makeClient($connection), '["REQ","sub-1",{"authors":[]},{"since":2,"until":1}]');
        delay(0.01);

        $this->assertSame(['["EOSE","sub-1"]'], $sent);
    }

    public function testAReqWithMoreFiltersThanThePolicyServesIsClosedByThePolicy(): void
    {
        $this->policy->method('allowSubscription')->willReturn(PolicyRejection::blocked('too many filters (max 5)'));
        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $sent[] = $json;
        });

        $this->router->route($this->makeClient($connection), '["REQ","sub-1"'.str_repeat(',{"kinds":[1]}', 25).']');

        $this->assertSame(['["CLOSED","sub-1","blocked: too many filters (max 5)"]'], $sent);
    }

    public function testACountWhoseFiltersCannotMatchDrawsOneAnswer(): void
    {
        $this->routeAgainstAStoreHoldingOneNote();

        $this->assertCount(1, $this->framesSentInAnswerTo('["COUNT","count-1",{"kinds":[]}]'));
    }

    public function testACountWhoseFiltersCannotMatchIsAnsweredWithZero(): void
    {
        $this->routeAgainstAStoreHoldingOneNote();

        $sent = $this->framesSentInAnswerTo('["COUNT","count-1",{"kinds":[]}]');

        $this->assertSame(0, RelayCountMessage::tryFromJson($sent[0] ?? '')?->getCount()->toInt());
    }

    public function testAReqWhosePubkeyConditionIsNotLowercaseHexDrawsOneAnswer(): void
    {
        $this->assertCount(1, $this->framesSentInAnswerTo(self::reqWithAnUppercasePubkeyCondition()));
    }

    public function testAReqWhosePubkeyConditionIsNotLowercaseHexIsAnsweredWithANotice(): void
    {
        $sent = $this->framesSentInAnswerTo(self::reqWithAnUppercasePubkeyCondition());

        $this->assertNotNull(NoticeMessage::tryFromJson($sent[0] ?? ''));
    }

    private static function reqWithAnUppercasePubkeyCondition(): string
    {
        return '["REQ","sub-1",{"kinds":[24133],"#p":["'.str_repeat('A', 64).'"]}]';
    }

    /**
     * @return list<string>
     */
    private function framesSentInAnswerTo(string $message): array
    {
        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $sent[] = $json;
        });

        $this->router->route($this->makeClient($connection), $message);

        return $sent;
    }

    private function routeAgainstAStoreHoldingOneNote(): void
    {
        $store = new InMemoryEventStore();
        $keyPair = KeyPair::generate($this->signatureService());
        $store->store(Rumour::draft($keyPair->getPublicKey(), EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('stored'))->sign($keyPair, $this->signatureService()));

        $this->policy->method('filterForClient')->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturnCallback($store->findByFilters(...));
        $this->eventStore->method('countByFilters')->willReturnCallback($store->countByFilters(...));
    }

    public function testAFaultOnAReadAReplacingReqOvertookSendsNoClosed(): void
    {
        [$replaced, $replacement] = [new DeferredFuture(), new DeferredFuture()];
        $connection = new RecordingClientConnection();
        $client = $this->makeClient($connection);
        $this->storeAnsweringInTurn($replaced, $replacement);

        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        self::failRead($replaced);
        self::answerRead($replacement, new StoredEventCollection([]));

        $this->assertSame(['["EOSE","x"]'], $connection->sentFrames());
    }

    public function testAFaultOnAReadAReplacingReqOvertookLeavesTheReplacementOpen(): void
    {
        [$replaced, $replacement] = [new DeferredFuture(), new DeferredFuture()];
        $client = $this->makeClient(new RecordingClientConnection());
        $this->storeAnsweringInTurn($replaced, $replacement);

        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        self::failRead($replaced);

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($client->getId()));
    }

    public function testAReadAReplacingReqOvertookSendsNothingOfItsOwn(): void
    {
        [$replaced, $replacement] = [new DeferredFuture(), new DeferredFuture()];
        $connection = new RecordingClientConnection();
        $client = $this->makeClient($connection);
        $this->storeAnsweringInTurn($replaced, $replacement);

        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        self::answerRead($replaced, new StoredEventCollection([self::storedNote()]));
        self::answerRead($replacement, new StoredEventCollection([]));

        $this->assertSame(['["EOSE","x"]'], $connection->sentFrames());
    }

    public function testAReadOfASubscriptionTheClientClosedSendsNothing(): void
    {
        $read = new DeferredFuture();
        $connection = new RecordingClientConnection();
        $client = $this->makeClient($connection);
        $this->storeAnsweringInTurn($read);

        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        $this->router->route($client, '["CLOSE","x"]');
        self::answerRead($read, new StoredEventCollection([self::storedNote()]));

        $this->assertSame([], $connection->sentFrames());
    }

    public function testAFaultOnAReadOfASubscriptionTheClientClosedSendsNothing(): void
    {
        $read = new DeferredFuture();
        $connection = new RecordingClientConnection();
        $client = $this->makeClient($connection);
        $this->storeAnsweringInTurn($read);

        $this->routeAndLetTheReadStart($client, '["REQ","x",{"kinds":[1]}]');
        $this->router->route($client, '["CLOSE","x"]');
        self::failRead($read);

        $this->assertSame([], $connection->sentFrames());
    }

    /**
     * @param DeferredFuture<mixed> ...$reads
     */
    private function storeAnsweringInTurn(DeferredFuture ...$reads): void
    {
        $this->policy->method('filterForClient')->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->eventStore->method('findByFilters')->willReturnCallback(static function () use (&$reads): StoredEventCollection {
            $read = array_shift($reads) ?? throw new RuntimeException('the store was read more often than the test expects');
            $answer = $read->getFuture()->await();

            return $answer instanceof StoredEventCollection ? $answer : throw new RuntimeException('a read was answered with something other than stored events');
        });
    }

    private function routeAndLetTheReadStart(RelayClient $client, string $message): void
    {
        $this->router->route($client, $message);
        delay(0.01);
    }

    /**
     * @param DeferredFuture<mixed> $read
     */
    private static function answerRead(DeferredFuture $read, StoredEventCollection $events): void
    {
        $read->complete($events);
        delay(0.01);
    }

    /**
     * @param DeferredFuture<mixed> $read
     */
    private static function failRead(DeferredFuture $read): void
    {
        $read->error(new TestDoubleException('store unavailable'));
        delay(0.01);
    }

    private static function storedNote(): StoredEvent
    {
        return StoredEvent::of(EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('stored before the replacement'),
        )));
    }

    public function testSendsNoticeForInvalidMessage(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = NoticeMessage::tryFromJson($json);

                return null !== $message && str_contains($message->getMessage(), 'Invalid message');
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, 'invalid');
    }

    public function testSendsNoticeForUnexpectedError(): void
    {
        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $sent[] = $json;

            if (1 === count($sent)) {
                throw new RuntimeException('unexpected');
            }
        });
        $client = $this->makeClient($connection);

        $this->router->route($client, 'invalid');

        $this->assertSame('Internal server error', NoticeMessage::tryFromJson($sent[1] ?? '')?->getMessage());
    }
}
