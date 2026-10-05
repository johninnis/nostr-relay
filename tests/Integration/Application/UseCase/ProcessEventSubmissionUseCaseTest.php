<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\UseCase;

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
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPublisher;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventAudience;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\PublishingGate;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessEventSubmissionUseCaseTest extends TestCase
{
    private RelayPolicyInterface&Stub $policy;
    private RateLimiterInterface&Stub $rateLimiter;
    private InMemoryClientRegistry $clientRegistry;
    private ProcessEventSubmissionUseCase $useCase;
    private RelayClient $client;
    private SignatureServiceInterface $sigService;
    private bool $rateLimited = false;

    private function signatureService(): SignatureServiceInterface
    {
        return $this->sigService ??= Secp256k1Signer::create();
    }

    protected function setUp(): void
    {
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('tryConsume')->willReturnCallback(fn (): bool => !$this->rateLimited);
        $this->useCase = $this->makeUseCase();
        $this->client = $this->makeClient();
    }

    private function makeUseCase(?RelayEventStoreInterface $eventStore = null): ProcessEventSubmissionUseCase
    {
        $eventStore ??= $this->createStub(RelayEventStoreInterface::class);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientRegistry = new InMemoryClientRegistry(
            $metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );
        $messenger = new ClientMessenger($this->clientRegistry);

        $distributor = new EventDistributor(
            new EventAudience($this->policy, $subscriptionRegistry, $this->clientRegistry),
            $messenger,
            $logger,
        );

        $pipeline = new AcceptedEventPipeline(
            $eventStore,
            new AcceptedEventPublisher(
                $this->clientRegistry,
                $distributor,
                new AmphpDeferredExecutor(),
            ),
            new EventDeletionProcessor($eventStore, $logger),
        );

        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        return new ProcessEventSubmissionUseCase(
            new EventAdmission(
                new RateLimitGate($this->rateLimiter, $this->policy),
                new EventValidityGate(
                    new EventValidator($this->signatureService(), new NipComplianceValidator($this->signatureService())),
                    new SystemClock(),
                ),
                new PublishingGate($this->policy, $authenticationRegistry, new AuthChallengeIssuer($authenticationRegistry)),
            ),
            $pipeline,
            $logger,
        );
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientRegistry->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    private function createSignedEvent(?EventKind $kind = null): Event
    {
        $keyPair = KeyPair::generate($this->signatureService());

        return Rumour::draft(
            $keyPair->getPublicKey(),
            $kind ?? EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello world'),
            new TagCollection(),
        )->sign($keyPair, $this->signatureService());
    }

    private function createSignedDeletionEvent(TagCollection $tags, ?KeyPair $keyPair = null): Event
    {
        $keyPair ??= KeyPair::generate($this->signatureService());

        return Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::EVENT_DELETION),
            EventContent::fromString('spam'),
            $tags,
        )->sign($keyPair, $this->signatureService());
    }

    public function testSuccessfulEventStoreAndDistribute(): void
    {
        $event = $this->createSignedEvent();

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);

        $useCase = $this->makeUseCase($eventStore);

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

        $useCase = $this->makeUseCase($eventStore);

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

        $useCase = $this->makeUseCase($eventStore);

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
            ->willReturn(PolicyRejection::blocked('not allowed'));

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('blocked', $replies[0]->getMessage());
    }

    public function testAnAlreadyExpiredEventIsRefusedAsInvalid(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('too late'),
            new TagCollection([Tag::tryFromArray(['expiration', '1'])]),
        )->sign($keyPair, $this->signatureService());
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('store');
        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertSame('invalid: event has expired', $replies[0]->getMessage());
    }

    public function testAnAddressableEventWhoseDTagsDisagreeIsRefusedAsInvalid(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::LONGFORM_CONTENT),
            EventContent::fromString('which article?'),
            new TagCollection([Tag::identifier('first'), Tag::identifier('second')]),
        )->sign($keyPair, $this->signatureService());
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('store');
        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringStartsWith('invalid:', $replies[0]->getMessage());
    }

    public function testRateLimitReturnsRateLimitedMessage(): void
    {
        $event = $this->createSignedEvent();

        $this->rateLimited = true;

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

        $useCase = $this->makeUseCase($eventStore);

        $replies = $useCase->execute($this->makeClient(), $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
    }

    public function testAuthRequiredReturnsAuthChallengeAndOk(): void
    {
        $event = $this->createSignedEvent();

        $this->policy->method('allowEventSubmission')
            ->willReturn(PolicyRejection::authRequired('auth needed'));

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(OkMessage::class, $replies[1]);
        $this->assertFalse($replies[1]->isAccepted());
        $this->assertStringContainsString('auth-required', $replies[1]->getMessage());
    }

    public function testAProtectedEventFromAnUnauthenticatedClientIsAnsweredWithAChallengeAndAuthRequired(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello members of the secret group'),
            new TagCollection([Tag::fromArray([TagType::PROTECTED])]),
        )->sign($keyPair, $this->signatureService());

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(OkMessage::class, $replies[1]);
        $this->assertFalse($replies[1]->isAccepted());
        $this->assertSame('auth-required: this event may only be published by its author', $replies[1]->getMessage());
    }

    public function testOfferedAuthChallengeIsReturnedToTheAdmittedClient(): void
    {
        $event = $this->createSignedEvent();

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $useCase = $this->makeUseCase($eventStore);

        $this->policy->method('offersAuthChallenge')->willReturn(true);

        $replies = $useCase->execute($this->makeClient(), $event);

        // The challenge is offered only after allowEventSubmission admits the event, so its presence alongside an accepting OK proves admission (not rejection).
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(OkMessage::class, $replies[1]);
        $this->assertTrue($replies[1]->isAccepted());
    }

    public function testDeletionEventTriggersDeleteByEventIds(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $targetEvent = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('target'),
            new TagCollection(),
        )->sign($keyPair, $this->signatureService());

        $tags = new TagCollection([
            Tag::event($targetEvent->getId()),
            Tag::tryFromArray(['k', '1']),
        ]);
        $deletionEvent = $this->createSignedDeletionEvent($tags, $keyPair);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->willReturn(new StoredEventCollection([StoredEvent::of($targetEvent)]));
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
        $victimEvent = Rumour::draft(
            $victimKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('victim'),
            new TagCollection(),
        )->sign($victimKeyPair, $this->signatureService());

        $attackerKeyPair = KeyPair::generate($this->signatureService());
        $tags = new TagCollection([
            Tag::event($victimEvent->getId()),
            Tag::tryFromArray(['k', '1']),
        ]);
        $deletionEvent = $this->createSignedDeletionEvent($tags, $attackerKeyPair);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->willReturn(new StoredEventCollection([StoredEvent::of($victimEvent)]));
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
            Tag::tryFromArray(['a', $coordinate]),
            Tag::tryFromArray(['k', '30023']),
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
                $this->callback(static fn (Timestamp $until): bool => $until->equals($event->getCreatedAt())),
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
            Tag::tryFromArray(['a', $victimCoordinate]),
            Tag::tryFromArray(['k', '30023']),
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
