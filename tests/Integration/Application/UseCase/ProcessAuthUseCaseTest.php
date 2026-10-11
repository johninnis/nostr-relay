<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\UseCase;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Application\Service\Nip42Validator;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\Service\Nip42EventChecker;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\Nip42Handshake;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Infrastructure\Monitoring\InMemoryMetricsCollector;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessAuthUseCaseTest extends TestCase
{
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private InMemoryClientRegistry $clientRegistry;
    private ProcessAuthUseCase $useCase;
    private RelayPolicyInterface $policy;
    private RelayClient $client;
    private ClientConnectionInterface&Stub $connection;
    private KeyPair $keyPair;
    private SignatureServiceInterface $sigService;
    private InMemorySubscriptionRegistry $subscriptionRegistry;

    private function signatureService(): SignatureServiceInterface
    {
        return $this->sigService ??= Secp256k1Signer::create();
    }

    private function eventValidator(): EventValidator
    {
        return new EventValidator($this->signatureService(), new NipComplianceValidator($this->signatureService()));
    }

    protected function setUp(): void
    {
        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $this->keyPair = KeyPair::generate($this->signatureService());

        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->policy->method('allowsAuthentication')->willReturn(null);

        $this->connection = $this->createStub(ClientConnectionInterface::class);
        $this->clientRegistry = new InMemoryClientRegistry(
            new InMemoryMetricsCollector(),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );
        $this->client = $this->clientRegistry->registerClient(
            $this->connection,
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry(new InMemoryMetricsCollector(), new NullLogger());

        $this->useCase = $this->useCaseFor($this->policy);
    }

    public function testASuccessfulAuthenticationIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAValidAuth()));
    }

    public function testASuccessfulAuthenticationIsAccepted(): void
    {
        $this->assertTrue(self::replyOfType($this->repliesToAValidAuth(), OkMessage::class)?->isAccepted());
    }

    public function testSuccessfulAuthentication(): void
    {
        $this->repliesToAValidAuth();

        $this->assertTrue($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAFrameNamingNoLiveChallengeNeverReachesThePolicy(): void
    {
        $policy = $this->createMock(RelayPolicyInterface::class);
        $policy->expects($this->never())->method('allowsAuthentication');

        $this->repliesToAFrameNamingNoLiveChallenge($policy, $this->createStub(EventValidatorInterface::class));
    }

    public function testAFrameNamingNoLiveChallengeIsRefusedWithoutVerifyingItsSignature(): void
    {
        $validator = $this->createMock(EventValidatorInterface::class);
        $validator->expects($this->never())->method('validateEvent');

        $this->repliesToAFrameNamingNoLiveChallenge($this->createStub(RelayPolicyInterface::class), $validator);
    }

    public function testAFrameNamingNoLiveChallengeIsAnsweredAuthRequired(): void
    {
        $replies = $this->repliesToAFrameNamingNoLiveChallenge(
            $this->createStub(RelayPolicyInterface::class),
            $this->createStub(EventValidatorInterface::class),
        );

        $this->assertStringStartsWith('auth-required: ', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testAnAuthLeavesAnOpenSubscriptionAsItWasAdmitted(): void
    {
        $admitted = new FilterCollection([Filter::tryFromArray(['kinds' => [1], 'authors' => [KeyMother::alicePublicKey()->toHex()]])]);
        $this->subscriptionRegistry->addSubscription($this->client->getId(), Subscription::create(SubscriptionIdMother::from('open'), $admitted, SubscriptionState::Live));

        $this->repliesToAValidAuth();

        $this->assertSame($admitted, $this->subscriptionRegistry->getSubscriptionsForClient($this->client->getId())->get(SubscriptionIdMother::from('open'))?->getFilters());
    }

    public function testAnAuthWithAnOpenSubscriptionIsAnsweredWithOneOk(): void
    {
        $this->subscriptionRegistry->addSubscription($this->client->getId(), Subscription::create(SubscriptionIdMother::from('open'), new FilterCollection([Filter::from()]), SubscriptionState::Live));

        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAValidAuth()));
    }

    public function testAnAuthWithAnOpenSubscriptionStreamsNothing(): void
    {
        $sent = [];
        $this->connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $sent[] = $json;
        });
        $this->subscriptionRegistry->addSubscription($this->client->getId(), Subscription::create(SubscriptionIdMother::from('open'), new FilterCollection([Filter::from()]), SubscriptionState::Live));

        $this->repliesToAValidAuth();

        $this->assertSame([], $sent);
    }

    public function testAnAuthFromANonTenantIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnAuthFromANonTenant()));
    }

    public function testRejectsAuthenticationFromNonTenantPubkey(): void
    {
        $this->assertFalse(self::replyOfType($this->repliesToAnAuthFromANonTenant(), OkMessage::class)?->isAccepted());
    }

    public function testAnAuthFromANonTenantIsAnsweredRestricted(): void
    {
        $replies = $this->repliesToAnAuthFromANonTenant();

        $this->assertStringContainsString('restricted', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testAnAuthFromANonTenantLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnAuthFromANonTenant();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnAuthWithNoChallengeOutstandingIsAnsweredWithAChallengeThenAnOk(): void
    {
        $replies = $this->repliesToAnAuthWithNoChallengeOutstanding();

        $this->assertSame([AuthMessage::class, OkMessage::class], array_map(get_class(...), $replies));
    }

    public function testAnAuthWithNoChallengeOutstandingIsRefused(): void
    {
        $replies = $this->repliesToAnAuthWithNoChallengeOutstanding();

        $this->assertFalse(self::replyOfType($replies, OkMessage::class)?->isAccepted());
    }

    public function testAnAuthWithNoChallengeOutstandingIsAnsweredAuthRequired(): void
    {
        $replies = $this->repliesToAnAuthWithNoChallengeOutstanding();

        $this->assertStringContainsString('auth-required', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testIssuesChallengeWhenNoneOutstanding(): void
    {
        $this->repliesToAnAuthWithNoChallengeOutstanding();

        $this->assertNotNull($this->authenticationRegistry->getChallenge($this->client->getId()));
    }

    public function testAnAuthWithNoChallengeOutstandingLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnAuthWithNoChallengeOutstanding();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnAuthNamingTheWrongChallengeIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnAuthNamingTheWrongChallenge()));
    }

    public function testRejectsInvalidChallenge(): void
    {
        $this->assertFalse(self::replyOfType($this->repliesToAnAuthNamingTheWrongChallenge(), OkMessage::class)?->isAccepted());
    }

    public function testAnAuthNamingTheWrongChallengeIsAnsweredThatTheChallengeDoesNotMatch(): void
    {
        $replies = $this->repliesToAnAuthNamingTheWrongChallenge();

        $this->assertStringContainsString(
            'auth-required: Challenge does not match',
            self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '',
        );
    }

    public function testAnAuthNamingTheWrongChallengeLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnAuthNamingTheWrongChallenge();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnAuthNamingAnotherRelayIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnAuthNamingAnotherRelay()));
    }

    public function testRejectsInvalidRelayUrl(): void
    {
        $this->assertFalse(self::replyOfType($this->repliesToAnAuthNamingAnotherRelay(), OkMessage::class)?->isAccepted());
    }

    public function testAnAuthNamingAnotherRelayIsAnsweredThatTheRelayUrlDoesNotMatch(): void
    {
        $replies = $this->repliesToAnAuthNamingAnotherRelay();

        $this->assertStringContainsString(
            'invalid: Relay URL does not match',
            self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '',
        );
    }

    public function testAnAuthNamingAnotherRelayLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnAuthNamingAnotherRelay();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnUnsignedAuthClaimingAVictimIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnUnsignedAuthClaimingAVictim()));
    }

    public function testRejectsUnsignedAuthEventClaimingVictimPubkey(): void
    {
        $this->assertFalse(self::replyOfType($this->repliesToAnUnsignedAuthClaimingAVictim(), OkMessage::class)?->isAccepted());
    }

    public function testAnUnsignedAuthClaimingAVictimIsAnsweredThatItsSignatureIsInvalid(): void
    {
        $replies = $this->repliesToAnUnsignedAuthClaimingAVictim();

        $this->assertStringContainsString('signature is invalid', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testAnUnsignedAuthClaimingAVictimLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnUnsignedAuthClaimingAVictim();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnUnsignedAuthClaimingAVictimNeverAuthenticatesTheClientAsTheVictim(): void
    {
        $this->repliesToAnUnsignedAuthClaimingAVictim();

        $this->assertFalse($this->authenticationRegistry->isAuthenticatedAs($this->client->getId(), KeyMother::bobPublicKey()));
    }

    public function testAnAuthWithAnExpiredTimestampIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnAuthWithAnExpiredTimestamp()));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $this->assertFalse(self::replyOfType($this->repliesToAnAuthWithAnExpiredTimestamp(), OkMessage::class)?->isAccepted());
    }

    public function testAnAuthWithAnExpiredTimestampIsAnsweredAboutItsTimestamp(): void
    {
        $replies = $this->repliesToAnAuthWithAnExpiredTimestamp();

        $this->assertStringContainsString('timestamp', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testAnAuthWithAnExpiredTimestampIsAnsweredInvalid(): void
    {
        $replies = $this->repliesToAnAuthWithAnExpiredTimestamp();

        $this->assertStringStartsWith('invalid: ', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testAnAuthStatingTwoChallengesIsAnsweredInvalid(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $event = Rumour::draft(
            $this->keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection([
                Tag::tryFromArray(['relay', 'wss://relay.example.com']),
                Tag::tryFromArray(['challenge', (string) $challenge]),
                Tag::tryFromArray(['challenge', 'a-second-challenge']),
            ]),
        )->sign($this->keyPair, $this->signatureService());

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertSame('invalid: Event must state exactly one challenge', self::replyOfType($replies, OkMessage::class)?->getMessage());
    }

    public function testAnAuthWithAnExpiredTimestampLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnAuthWithAnExpiredTimestamp();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnAuthStaleByTheInjectedClockIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnAuthStaleByTheInjectedClock()));
    }

    public function testRejectsTimestampAgainstTheInjectedClockNotWallClock(): void
    {
        $this->assertFalse(self::replyOfType($this->repliesToAnAuthStaleByTheInjectedClock(), OkMessage::class)?->isAccepted());
    }

    public function testAnAuthStaleByTheInjectedClockIsAnsweredAboutItsTimestamp(): void
    {
        $replies = $this->repliesToAnAuthStaleByTheInjectedClock();

        $this->assertStringContainsString('timestamp', self::replyOfType($replies, OkMessage::class)?->getMessage() ?? '');
    }

    public function testAnAuthStaleByTheInjectedClockLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnAuthStaleByTheInjectedClock();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnExpiredAuthLeavesTheClientUnauthenticated(): void
    {
        $this->repliesToAnExpiredAuth();

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAnExpiredAuthIsAnsweredWithOneOk(): void
    {
        $this->assertSame([OkMessage::class], array_map(get_class(...), $this->repliesToAnExpiredAuth()));
    }

    public function testAnExpiredAuthIsAnsweredInvalid(): void
    {
        $replies = $this->repliesToAnExpiredAuth();

        $this->assertSame('invalid: event has expired', self::replyOfType($replies, OkMessage::class)?->getMessage());
    }

    /**
     * @template T of RelayMessage
     *
     * @param list<RelayMessage> $replies
     * @param class-string<T>    $type
     *
     * @return T|null
     */
    private static function replyOfType(array $replies, string $type): ?RelayMessage
    {
        $reply = array_find($replies, static fn (RelayMessage $reply): bool => $reply instanceof $type);

        return $reply instanceof $type ? $reply : null;
    }

    private function relayConfig(): RelayConfigInterface
    {
        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        return $config;
    }

    private function useCaseFor(RelayPolicyInterface $policy, ClockInterface $clock = new SystemClock()): ProcessAuthUseCase
    {
        return new ProcessAuthUseCase(
            new Nip42Handshake(
                $this->authenticationRegistry,
                new AuthEventVerifier($this->relayConfig(), $policy, new Nip42Validator(new Nip42EventChecker(), $clock)),
                new AuthChallengeIssuer($this->authenticationRegistry),
            ),
            new EventValidityGate($this->eventValidator(), $clock),
        );
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAValidAuth(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        return $this->useCase->execute($this->client, $this->createAuthEvent($challenge, 'wss://relay.example.com'));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAFrameNamingNoLiveChallenge(RelayPolicyInterface $policy, EventValidatorInterface $validator): array
    {
        $useCase = new ProcessAuthUseCase(
            new Nip42Handshake(
                $this->authenticationRegistry,
                new AuthEventVerifier($this->relayConfig(), $policy, new Nip42Validator(new Nip42EventChecker(), new SystemClock())),
                new AuthChallengeIssuer($this->authenticationRegistry),
            ),
            new EventValidityGate($validator, new SystemClock()),
        );

        $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        return $useCase->execute($this->client, $this->createAuthEvent(Challenge::fromString('never-issued'), 'wss://relay.example.com'));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAuthFromANonTenant(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(PolicyRejection::restricted('authentication is limited to relay tenants'));

        return $this->useCaseFor($policy)
            ->execute($this->client, $this->createAuthEvent($challenge, 'wss://relay.example.com'));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAuthWithNoChallengeOutstanding(): array
    {
        return $this->useCase->execute($this->client, $this->createAuthEvent(Challenge::fromString('some-challenge'), 'wss://relay.example.com'));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAuthNamingTheWrongChallenge(): array
    {
        $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        return $this->useCase->execute($this->client, $this->createAuthEvent(Challenge::fromString('wrong-challenge'), 'wss://relay.example.com'));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAuthNamingAnotherRelay(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        return $this->useCase->execute($this->client, $this->createAuthEvent($challenge, 'wss://wrong-relay.example.com'));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnUnsignedAuthClaimingAVictim(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $forged = EventMother::fromRumour(Rumour::draft(
            KeyMother::bobPublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection([
                Tag::tryFromArray(['relay', 'wss://relay.example.com']),
                Tag::tryFromArray(['challenge', (string) $challenge]),
            ]),
        ));

        return $this->useCase->execute($this->client, $forged);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAuthWithAnExpiredTimestamp(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        return $this->useCase->execute($this->client, $this->createAuthEventWithTimestamp($challenge, 'wss://relay.example.com', time() - 700));
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnAuthStaleByTheInjectedClock(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEventWithTimestamp($challenge, 'wss://relay.example.com', time());

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(null);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(time() + 601));

        return $this->useCaseFor($policy, $clock)->execute($this->client, $event);
    }

    /**
     * @return list<RelayMessage>
     */
    private function repliesToAnExpiredAuth(): array
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $expired = Rumour::draft(
            $this->keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection([
                Tag::tryFromArray(['relay', 'wss://relay.example.com']),
                Tag::tryFromArray(['challenge', (string) $challenge]),
                Tag::tryFromArray(['expiration', (string) (time() - 1)]),
            ]),
        )->sign($this->keyPair, $this->signatureService());

        return $this->useCase->execute($this->client, $expired);
    }

    private function createAuthEvent(Challenge $challenge, string $relayUrl): Event
    {
        return $this->createAuthEventWithTimestamp($challenge, $relayUrl, time());
    }

    private function createAuthEventWithTimestamp(Challenge $challenge, string $relayUrl, int $timestamp): Event
    {
        return Rumour::draft(
            $this->keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection([
                Tag::tryFromArray(['relay', $relayUrl]),
                Tag::tryFromArray(['challenge', (string) $challenge]),
            ]),
            Timestamp::fromInt($timestamp),
        )->sign($this->keyPair, $this->signatureService());
    }
}
