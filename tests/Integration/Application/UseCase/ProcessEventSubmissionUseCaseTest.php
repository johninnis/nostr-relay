<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPublisher;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessEventSubmissionUseCaseTest extends TestCase
{
    private RelayPolicyInterface&Stub $policy;
    private RateLimiterInterface&Stub $rateLimiter;
    private InMemoryClientRegistry $clientManager;
    private ProcessEventSubmissionUseCase $useCase;
    private RelayClient $client;
    private SignatureServiceInterface $sigService;

    private function signatureService(): SignatureServiceInterface
    {
        return $this->sigService ??= Secp256k1Signer::create();
    }

    protected function setUp(): void
    {
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->useCase = $this->makeUseCase();
        $this->client = $this->makeClient();
    }

    private function makeUseCase(
        ?RelayEventStoreInterface $eventStore = null,
        ?MetricsCollectorInterface $metrics = null,
    ): ProcessEventSubmissionUseCase {
        $eventStore ??= $this->createStub(RelayEventStoreInterface::class);
        $metrics ??= $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $subscriptionManager = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientManager = new InMemoryClientRegistry(
            $metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );
        $messenger = new ClientMessenger($this->clientManager);

        $distributor = new EventDistributor(
            $this->policy,
            $subscriptionManager,
            $this->clientManager,
            $messenger,
            $logger,
        );

        $acceptedEventPublisher = new AcceptedEventPublisher(
            $this->clientManager,
            $distributor,
            new AmphpDeferredExecutor(),
        );

        $pipeline = new AcceptedEventPipeline(
            $eventStore,
            $acceptedEventPublisher,
            new EventDeletionProcessor($eventStore, $logger),
            $logger,
        );

        return new ProcessEventSubmissionUseCase(
            new EventAdmission(
                $this->policy,
                $this->rateLimiter,
                new EventValidator($this->signatureService(), new NipComplianceValidator($this->signatureService())),
            ),
            $pipeline,
            new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()),
            $this->clientManager,
            $logger,
        );
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientManager->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    private function createSignedEvent(?EventKind $kind = null): Event
    {
        $keyPair = KeyPair::generate($this->signatureService());

        return (new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            $kind ?? EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString('hello world'),
        ))->sign($keyPair, $this->signatureService());
    }

    private function createSignedDeletionEvent(TagCollection $tags, ?KeyPair $keyPair = null): Event
    {
        $keyPair ??= KeyPair::generate($this->signatureService());

        return (new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::EVENT_DELETION),
            $tags,
            EventContent::fromString('spam'),
        ))->sign($keyPair, $this->signatureService());
    }

    public function testSuccessfulEventStoreAndDistribute(): void
    {
        $event = $this->createSignedEvent();

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);

        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsReceived');

        $useCase = $this->makeUseCase($eventStore, $metrics);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testDuplicateEventReturnsNotOk(): void
    {
        $event = $this->createSignedEvent();

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Duplicate);

        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsReceived');

        $useCase = $this->makeUseCase($eventStore, $metrics);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertSame('duplicate: event already exists', $replies[0]->getMessage());
    }

    public function testSupersededEventReturnsNotOkWithNewerVersionMessage(): void
    {
        $event = $this->createSignedEvent();

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Superseded);

        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsReceived');

        $useCase = $this->makeUseCase($eventStore, $metrics);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertSame('duplicate: newer version already exists', $replies[0]->getMessage());
    }

    public function testPolicyViolationReturnsBlockedMessage(): void
    {
        $event = $this->createSignedEvent();

        $this->policy->method('allowEventSubmission')
            ->willThrowException(new PolicyViolationException('not allowed'));

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('blocked', $replies[0]->getMessage());
    }

    public function testRateLimitReturnsRateLimitedMessage(): void
    {
        $event = $this->createSignedEvent();

        $this->rateLimiter->method('checkLimit')
            ->willThrowException(RateLimitException::forKey('127.0.0.1'));

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('rate-limited', $replies[0]->getMessage());
    }

    public function testEphemeralEventSkipsStorageAndReturnsOk(): void
    {
        $event = $this->createSignedEvent(EventKind::fromInt(20001));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('store');

        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsReceived');

        $useCase = $this->makeUseCase($eventStore, $metrics);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testAuthRequiredReturnsAuthChallengeAndOk(): void
    {
        $event = $this->createSignedEvent();

        $this->policy->method('allowEventSubmission')
            ->willThrowException(new AuthRequiredException('auth needed'));

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(OkMessage::class, $replies[1]);
        $this->assertFalse($replies[1]->isAccepted());
        $this->assertStringContainsString('auth-required', $replies[1]->getMessage());
    }

    public function testDeletionEventTriggersDeleteByEventIds(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $targetEvent = (new Rumour(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString('target'),
        ))->sign($keyPair, $this->signatureService());

        $tags = new TagCollection([
            Tag::event($targetEvent->getId()),
            Tag::fromArray(['k', '1']),
        ]);
        $deletionEvent = $this->createSignedDeletionEvent($tags, $keyPair);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->willReturn(new EventCollection([$targetEvent]));
        $eventStore->expects($this->once())
            ->method('deleteByEventIds')
            ->with(
                $this->callback(static function (EventIdCollection $eventIds) use ($targetEvent): bool {
                    return 1 === $eventIds->count() && $targetEvent->getId()->toHex() === $eventIds->toArray()[0]->toHex();
                }),
                $this->callback(static function (PublicKey $author) use ($keyPair): bool {
                    return $author->equals($keyPair->getPublicKey());
                }),
            )
            ->willReturn(1);

        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->makeClient(), $deletionEvent);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testDeletionEventSkipsEventIdsAuthoredBySomeoneElse(): void
    {
        $victimKeyPair = KeyPair::generate($this->signatureService());
        $victimEvent = (new Rumour(
            $victimKeyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString('victim'),
        ))->sign($victimKeyPair, $this->signatureService());

        $attackerKeyPair = KeyPair::generate($this->signatureService());
        $tags = new TagCollection([
            Tag::event($victimEvent->getId()),
            Tag::fromArray(['k', '1']),
        ]);
        $deletionEvent = $this->createSignedDeletionEvent($tags, $attackerKeyPair);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->willReturn(new EventCollection([$victimEvent]));
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->client, $deletionEvent);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testDeletionEventTriggersDeleteByCoordinates(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $coordinate = '30023:'.$keyPair->getPublicKey()->toHex().':my-article';
        $tags = new TagCollection([
            Tag::fromArray(['a', $coordinate]),
            Tag::fromArray(['k', '30023']),
        ]);
        $event = $this->createSignedDeletionEvent($tags, $keyPair);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->expects($this->once())
            ->method('deleteByCoordinates')
            ->with(
                $this->callback(static function (EventCoordinateCollection $coordinates) use ($coordinate): bool {
                    return 1 === $coordinates->count() && $coordinate === (string) $coordinates->toArray()[0];
                }),
                $this->callback(static function (PublicKey $author) use ($keyPair): bool {
                    return $author->equals($keyPair->getPublicKey());
                }),
            )
            ->willReturn(1);

        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testDeletionEventSkipsCoordinatesOwnedBySomeoneElse(): void
    {
        $victimKeyPair = KeyPair::generate($this->signatureService());
        $attackerKeyPair = KeyPair::generate($this->signatureService());
        $victimCoordinate = '30023:'.$victimKeyPair->getPublicKey()->toHex().':target-article';
        $tags = new TagCollection([
            Tag::fromArray(['a', $victimCoordinate]),
            Tag::fromArray(['k', '30023']),
        ]);
        $deletionEvent = $this->createSignedDeletionEvent($tags, $attackerKeyPair);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('findByFilters');
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->client, $deletionEvent);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testNonDeletionEventDoesNotTriggerDeletion(): void
    {
        $event = $this->createSignedEvent();

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }
}
