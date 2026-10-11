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
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
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
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\EventAcceptance;
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
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessEventSubmissionUseCaseTest extends TestCase
{
    private RelayPolicyInterface&Stub $policy;
    private RateLimiterInterface&Stub $rateLimiter;
    private InMemoryClientRegistry $clientRegistry;
    private InMemoryAuthenticationRegistry $authenticationRegistry;
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
            new EventAudience(new DeliveryGate($this->policy, new GiftWrapFilterRules()), $subscriptionRegistry, $this->clientRegistry),
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

        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        return new ProcessEventSubmissionUseCase(
            new EventAdmission(
                new RateLimitGate($this->rateLimiter, $this->policy),
                new EventValidityGate(
                    new EventValidator($this->signatureService(), new NipComplianceValidator($this->signatureService())),
                    new SystemClock(),
                ),
                new PublishingGate($this->policy, $this->authenticationRegistry, new AuthChallengeIssuer($this->authenticationRegistry)),
            ),
            new EventAcceptance($pipeline, new AuthChallengeIssuer($this->authenticationRegistry)),
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

    /**
     * @return iterable<string, array{EventStoreOutcome}>
     */
    public static function storeOutcomes(): iterable
    {
        yield 'stored' => [EventStoreOutcome::Stored];
        yield 'duplicate' => [EventStoreOutcome::Duplicate];
        yield 'superseded' => [EventStoreOutcome::Superseded];
    }

    #[DataProvider('storeOutcomes')]
    public function testEachStoreOutcomeIsAnsweredWithOneOk(EventStoreOutcome $outcome): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesWhenTheStoreAnswers($outcome)));
    }

    public function testAStoredEventIsAccepted(): void
    {
        $this->assertTrue(self::okReply($this->repliesWhenTheStoreAnswers(EventStoreOutcome::Stored))?->isAccepted());
    }

    public function testADuplicateIsRefused(): void
    {
        $this->assertFalse(self::okReply($this->repliesWhenTheStoreAnswers(EventStoreOutcome::Duplicate))?->isAccepted());
    }

    public function testADuplicateIsAnsweredThatTheEventAlreadyExists(): void
    {
        $this->assertSame(
            'duplicate: event already exists',
            self::okReply($this->repliesWhenTheStoreAnswers(EventStoreOutcome::Duplicate))?->getMessage(),
        );
    }

    public function testASupersededEventIsRefused(): void
    {
        $this->assertFalse(self::okReply($this->repliesWhenTheStoreAnswers(EventStoreOutcome::Superseded))?->isAccepted());
    }

    public function testASupersededEventIsAnsweredThatANewerVersionExists(): void
    {
        $this->assertSame(
            'duplicate: newer version already exists',
            self::okReply($this->repliesWhenTheStoreAnswers(EventStoreOutcome::Superseded))?->getMessage(),
        );
    }

    public function testAnEventThePolicyBlocksIsAnsweredWithOneOk(): void
    {
        $replies = $this->repliesUnderAPolicyRefusal(PolicyRejection::blocked('not allowed'));

        $this->assertSame([OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAnEventThePolicyBlocksIsRefused(): void
    {
        $replies = $this->repliesUnderAPolicyRefusal(PolicyRejection::blocked('not allowed'));

        $this->assertFalse(self::okReply($replies)?->isAccepted());
    }

    public function testAnEventThePolicyBlocksIsAnsweredBlocked(): void
    {
        $replies = $this->repliesUnderAPolicyRefusal(PolicyRejection::blocked('not allowed'));

        $this->assertStringContainsString('blocked', self::okReply($replies)?->getMessage() ?? '');
    }

    public function testAnAlreadyExpiredEventIsNeverStored(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('store');

        $this->repliesToAnAlreadyExpiredEvent($eventStore);
    }

    public function testAnAlreadyExpiredEventIsAnsweredWithOneOk(): void
    {
        $replies = $this->repliesToAnAlreadyExpiredEvent($this->createStub(RelayEventStoreInterface::class));

        $this->assertSame([OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAnAlreadyExpiredEventIsRefused(): void
    {
        $replies = $this->repliesToAnAlreadyExpiredEvent($this->createStub(RelayEventStoreInterface::class));

        $this->assertFalse(self::okReply($replies)?->isAccepted());
    }

    public function testAnAlreadyExpiredEventIsRefusedAsInvalid(): void
    {
        $replies = $this->repliesToAnAlreadyExpiredEvent($this->createStub(RelayEventStoreInterface::class));

        $this->assertSame('invalid: event has expired', self::okReply($replies)?->getMessage());
    }

    public function testAnAddressableEventWhoseDTagsDisagreeIsNeverStored(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('store');

        $this->repliesToAnAddressableEventWhoseDTagsDisagree($eventStore);
    }

    public function testAnAddressableEventWhoseDTagsDisagreeIsRefused(): void
    {
        $replies = $this->repliesToAnAddressableEventWhoseDTagsDisagree($this->createStub(RelayEventStoreInterface::class));

        $this->assertFalse(self::okReply($replies)?->isAccepted());
    }

    public function testAnAddressableEventWhoseDTagsDisagreeIsRefusedAsInvalid(): void
    {
        $replies = $this->repliesToAnAddressableEventWhoseDTagsDisagree($this->createStub(RelayEventStoreInterface::class));

        $this->assertStringStartsWith('invalid:', self::okReply($replies)?->getMessage() ?? '');
    }

    public function testARateLimitedEventIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesWhenRateLimited()));
    }

    public function testARateLimitedEventIsRefused(): void
    {
        $this->assertFalse(self::okReply($this->repliesWhenRateLimited())?->isAccepted());
    }

    public function testARateLimitedEventIsAnsweredRateLimited(): void
    {
        $this->assertStringContainsString('rate-limited', self::okReply($this->repliesWhenRateLimited())?->getMessage() ?? '');
    }

    public function testEphemeralEventSkipsStorage(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('store');

        $this->repliesToAnEphemeralEvent($eventStore);
    }

    public function testAnEphemeralEventIsAnsweredWithOneOk(): void
    {
        $replies = $this->repliesToAnEphemeralEvent($this->createStub(RelayEventStoreInterface::class));

        $this->assertSame([OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAnEphemeralEventIsAccepted(): void
    {
        $replies = $this->repliesToAnEphemeralEvent($this->createStub(RelayEventStoreInterface::class));

        $this->assertTrue(self::okReply($replies)?->isAccepted());
    }

    public function testAnAuthRequiredRefusalIsAnsweredWithAChallengeThenAnOk(): void
    {
        $replies = $this->repliesUnderAPolicyRefusal(PolicyRejection::authRequired('auth needed'));

        $this->assertSame([AuthMessage::class, OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAnAuthRequiredRefusalIsRefused(): void
    {
        $replies = $this->repliesUnderAPolicyRefusal(PolicyRejection::authRequired('auth needed'));

        $this->assertFalse(self::okReply($replies)?->isAccepted());
    }

    public function testAnAuthRequiredRefusalIsAnsweredAuthRequired(): void
    {
        $replies = $this->repliesUnderAPolicyRefusal(PolicyRejection::authRequired('auth needed'));

        $this->assertStringContainsString('auth-required', self::okReply($replies)?->getMessage() ?? '');
    }

    public function testAProtectedEventFromAnUnauthenticatedClientIsAnsweredWithAChallengeThenAnOk(): void
    {
        $replies = $this->repliesToAProtectedEventFromAnUnauthenticatedClient();

        $this->assertSame([AuthMessage::class, OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAProtectedEventFromAnUnauthenticatedClientIsRefused(): void
    {
        $this->assertFalse(self::okReply($this->repliesToAProtectedEventFromAnUnauthenticatedClient())?->isAccepted());
    }

    public function testAProtectedEventFromAnUnauthenticatedClientIsAnsweredAuthRequired(): void
    {
        $this->assertSame(
            'auth-required: this event may only be published by its author',
            self::okReply($this->repliesToAProtectedEventFromAnUnauthenticatedClient())?->getMessage(),
        );
    }

    public function testOfferedAuthChallengeIsReturnedToTheAdmittedClient(): void
    {
        $replies = $this->repliesToAnEventThePolicyOffersAChallengeFor();

        $this->assertSame([AuthMessage::class, OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAnEventOfferedAChallengeIsStillAccepted(): void
    {
        $this->assertTrue(self::okReply($this->repliesToAnEventThePolicyOffersAChallengeFor())?->isAccepted());
    }

    public function testADuplicateThePolicyOffersAChallengeForIsAnsweredWithOneOk(): void
    {
        $replies = $this->repliesToADuplicateThePolicyOffersAChallengeFor();

        $this->assertSame([OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testADuplicateThePolicyOffersAChallengeForIsAnsweredDuplicate(): void
    {
        $replies = $this->repliesToADuplicateThePolicyOffersAChallengeFor();

        $this->assertSame('duplicate: event already exists', self::okReply($replies)?->getMessage());
    }

    public function testADuplicateThePolicyOffersAChallengeForIssuesNoChallenge(): void
    {
        $this->repliesToADuplicateThePolicyOffersAChallengeFor();

        $this->assertNull($this->authenticationRegistry->getChallenge($this->client->getId()));
    }

    public function testAnEphemeralEventThePolicyOffersAChallengeForIsAnsweredWithAChallengeThenAnOk(): void
    {
        $this->policy->method('offersAuthChallenge')->willReturn(true);

        $replies = $this->useCase->execute($this->client, $this->createSignedEvent(EventKind::fromInt(20001)));

        $this->assertSame([AuthMessage::class, OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testADeletionLooksUpTheEventsItNamesById(): void
    {
        $author = KeyPair::generate($this->signatureService());
        $target = $this->noteBy($author);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->willReturn(new StoredEventCollection([StoredEvent::of($target)]));
        $eventStore->method('deleteByEventIds')->willReturn(1);

        $this->repliesToADeletionById($target, $author, $eventStore);
    }

    public function testDeletionEventTriggersDeleteByEventIds(): void
    {
        $author = KeyPair::generate($this->signatureService());
        $target = $this->noteBy($author);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($target)]));
        $eventStore->expects($this->once())
            ->method('deleteByEventIds')
            ->with(
                $this->callback(static function (EventIdCollection $eventIds) use ($target): bool {
                    return 1 === $eventIds->count() && $target->getId()->toHex() === $eventIds->toArray()[0]->toHex();
                }),
                $this->callback(static function (PublicKey $deleter) use ($author): bool {
                    return $deleter->equals($author->getPublicKey());
                }),
            )
            ->willReturn(1);

        $this->repliesToADeletionById($target, $author, $eventStore);
    }

    public function testADeletionOfTheAuthorsOwnEventIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToADeletionOfTheAuthorsOwnEvent()));
    }

    public function testADeletionOfTheAuthorsOwnEventIsAccepted(): void
    {
        $this->assertTrue(self::okReply($this->repliesToADeletionOfTheAuthorsOwnEvent())?->isAccepted());
    }

    public function testADeletionLooksUpAnotherAuthorsEventItNamesById(): void
    {
        $victimEvent = $this->noteBy(KeyPair::generate($this->signatureService()));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->willReturn(new StoredEventCollection([StoredEvent::of($victimEvent)]));

        $this->repliesToADeletionById($victimEvent, KeyPair::generate($this->signatureService()), $eventStore);
    }

    public function testDeletionEventSkipsEventIdsAuthoredBySomeoneElse(): void
    {
        $victimEvent = $this->noteBy(KeyPair::generate($this->signatureService()));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($victimEvent)]));
        $eventStore->expects($this->never())->method('deleteByEventIds');

        $this->repliesToADeletionById($victimEvent, KeyPair::generate($this->signatureService()), $eventStore);
    }

    public function testADeletionOfAnotherAuthorsEventDeletesNoCoordinates(): void
    {
        $victimEvent = $this->noteBy(KeyPair::generate($this->signatureService()));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($victimEvent)]));
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        $this->repliesToADeletionById($victimEvent, KeyPair::generate($this->signatureService()), $eventStore);
    }

    public function testADeletionOfAnotherAuthorsEventIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToADeletionOfAnotherAuthorsEvent()));
    }

    public function testADeletionOfAnotherAuthorsEventIsAccepted(): void
    {
        $this->assertTrue(self::okReply($this->repliesToADeletionOfAnotherAuthorsEvent())?->isAccepted());
    }

    public function testADeletionByCoordinateDeletesNoEventsById(): void
    {
        $author = KeyPair::generate($this->signatureService());

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->method('deleteByCoordinates')->willReturn(1);

        $this->makeUseCase($eventStore)->execute($this->makeClient(), $this->deletionOfCoordinate(self::articleCoordinate($author), $author));
    }

    public function testDeletionEventTriggersDeleteByCoordinates(): void
    {
        $author = KeyPair::generate($this->signatureService());
        $coordinate = self::articleCoordinate($author);
        $deletion = $this->deletionOfCoordinate($coordinate, $author);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->once())
            ->method('deleteByCoordinates')
            ->with(
                $this->callback(static function (EventCoordinateCollection $coordinates) use ($coordinate): bool {
                    return 1 === $coordinates->count() && $coordinate === (string) $coordinates->toArray()[0];
                }),
                $this->callback(static function (PublicKey $deleter) use ($author): bool {
                    return $deleter->equals($author->getPublicKey());
                }),
                $this->callback(static fn (Timestamp $until): bool => $until->equals($deletion->getCreatedAt())),
            )
            ->willReturn(1);

        $this->makeUseCase($eventStore)->execute($this->makeClient(), $deletion);
    }

    public function testADeletionOfTheAuthorsOwnCoordinateIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToADeletionOfTheAuthorsOwnCoordinate()));
    }

    public function testADeletionOfTheAuthorsOwnCoordinateIsAccepted(): void
    {
        $this->assertTrue(self::okReply($this->repliesToADeletionOfTheAuthorsOwnCoordinate())?->isAccepted());
    }

    public function testADeletionOfAnotherAuthorsCoordinateLooksNothingUp(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('findByFilters');

        $this->repliesToADeletionOfAnotherAuthorsCoordinate($eventStore);
    }

    public function testADeletionOfAnotherAuthorsCoordinateDeletesNoEventsById(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByEventIds');

        $this->repliesToADeletionOfAnotherAuthorsCoordinate($eventStore);
    }

    public function testDeletionEventSkipsCoordinatesOwnedBySomeoneElse(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        $this->repliesToADeletionOfAnotherAuthorsCoordinate($eventStore);
    }

    public function testADeletionOfAnotherAuthorsCoordinateIsAnsweredWithOneOk(): void
    {
        $replies = $this->repliesToADeletionOfAnotherAuthorsCoordinate($this->storeThatStores());

        $this->assertSame([OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testADeletionOfAnotherAuthorsCoordinateIsAccepted(): void
    {
        $this->assertTrue(self::okReply($this->repliesToADeletionOfAnotherAuthorsCoordinate($this->storeThatStores()))?->isAccepted());
    }

    public function testANonDeletionEventDeletesNoEventsById(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByEventIds');

        $this->makeUseCase($eventStore)->execute($this->makeClient(), $this->createSignedEvent());
    }

    public function testANonDeletionEventDeletesNoCoordinates(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        $this->makeUseCase($eventStore)->execute($this->makeClient(), $this->createSignedEvent());
    }

    /**
     * @param list<RelayMessage> $replies
     */
    private static function okReply(array $replies): ?OkMessage
    {
        $reply = array_find($replies, static fn (RelayMessage $reply): bool => $reply instanceof OkMessage);

        return $reply instanceof OkMessage ? $reply : null;
    }

    private static function articleCoordinate(KeyPair $author): string
    {
        return '30023:'.$author->getPublicKey()->toHex().':my-article';
    }

    private function storeThatStores(): RelayEventStoreInterface
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);

        return $eventStore;
    }

    private function noteBy(KeyPair $author): Event
    {
        return Rumour::draft(
            $author->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('target'),
            new TagCollection(),
        )->sign($author, $this->signatureService());
    }

    private function deletionOfCoordinate(string $coordinate, KeyPair $deleter): Event
    {
        return $this->createSignedDeletionEvent(new TagCollection([
            Tag::tryFromArray(['a', $coordinate]),
            Tag::tryFromArray(['k', '30023']),
        ]), $deleter);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesWhenTheStoreAnswers(EventStoreOutcome $outcome): array
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn($outcome);

        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $this->createSignedEvent());
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesUnderAPolicyRefusal(PolicyRejection $rejection): array
    {
        $this->policy->method('allowEventSubmission')->willReturn($rejection);

        return $this->useCase->execute($this->client, $this->createSignedEvent());
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAlreadyExpiredEvent(RelayEventStoreInterface $eventStore): array
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('too late'),
            new TagCollection([Tag::tryFromArray(['expiration', '1'])]),
        )->sign($keyPair, $this->signatureService());

        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $event);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAddressableEventWhoseDTagsDisagree(RelayEventStoreInterface $eventStore): array
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::LONGFORM_CONTENT),
            EventContent::fromString('which article?'),
            new TagCollection([Tag::identifier('first'), Tag::identifier('second')]),
        )->sign($keyPair, $this->signatureService());

        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $event);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesWhenRateLimited(): array
    {
        $this->rateLimited = true;

        return $this->useCase->execute($this->client, $this->createSignedEvent());
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnEphemeralEvent(RelayEventStoreInterface $eventStore): array
    {
        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $this->createSignedEvent(EventKind::fromInt(20001)));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAProtectedEventFromAnUnauthenticatedClient(): array
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello members of the secret group'),
            new TagCollection([Tag::fromArray([TagType::PROTECTED])]),
        )->sign($keyPair, $this->signatureService());

        return $this->useCase->execute($this->client, $event);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnEventThePolicyOffersAChallengeFor(): array
    {
        $useCase = $this->makeUseCase($this->storeThatStores());
        $this->policy->method('offersAuthChallenge')->willReturn(true);

        return $useCase->execute($this->makeClient(), $this->createSignedEvent());
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToADuplicateThePolicyOffersAChallengeFor(): array
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Duplicate);
        $this->useCase = $this->makeUseCase($eventStore);
        $this->client = $this->makeClient();
        $this->policy->method('offersAuthChallenge')->willReturn(true);

        return $this->useCase->execute($this->client, $this->createSignedEvent());
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToADeletionById(Event $target, KeyPair $deleter, RelayEventStoreInterface $eventStore): array
    {
        $deletion = $this->createSignedDeletionEvent(new TagCollection([
            Tag::event($target->getId()),
            Tag::tryFromArray(['k', '1']),
        ]), $deleter);

        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $deletion);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToADeletionOfTheAuthorsOwnEvent(): array
    {
        $author = KeyPair::generate($this->signatureService());
        $target = $this->noteBy($author);

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($target)]));
        $eventStore->method('deleteByEventIds')->willReturn(1);

        return $this->repliesToADeletionById($target, $author, $eventStore);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToADeletionOfAnotherAuthorsEvent(): array
    {
        $victimEvent = $this->noteBy(KeyPair::generate($this->signatureService()));

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($victimEvent)]));

        return $this->repliesToADeletionById($victimEvent, KeyPair::generate($this->signatureService()), $eventStore);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToADeletionOfTheAuthorsOwnCoordinate(): array
    {
        $author = KeyPair::generate($this->signatureService());

        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('store')->willReturn(EventStoreOutcome::Stored);
        $eventStore->method('deleteByCoordinates')->willReturn(1);

        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $this->deletionOfCoordinate(self::articleCoordinate($author), $author));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToADeletionOfAnotherAuthorsCoordinate(RelayEventStoreInterface $eventStore): array
    {
        $victim = KeyPair::generate($this->signatureService());
        $deletion = $this->deletionOfCoordinate(self::articleCoordinate($victim), KeyPair::generate($this->signatureService()));

        return $this->makeUseCase($eventStore)->execute($this->makeClient(), $deletion);
    }
}
