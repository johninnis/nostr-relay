<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\UseCase;

use Closure;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Application\Service\Nip42Validator;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Infrastructure\Monitoring\InMemoryMetricsCollector;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use Override;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessAuthUseCaseTest extends TestCase
{
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private InMemoryClientRegistry $clientRegistry;
    private ClientMessenger $messenger;
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

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->policy->method('allowsAuthentication')->willReturn(null);

        $this->connection = $this->createStub(ClientConnectionInterface::class);
        $this->clientRegistry = new InMemoryClientRegistry(
            new InMemoryMetricsCollector(),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );
        $this->messenger = new ClientMessenger($this->clientRegistry);
        $this->client = $this->clientRegistry->registerClient(
            $this->connection,
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry(new InMemoryMetricsCollector(), new NullLogger());

        $this->useCase = new ProcessAuthUseCase(
            $this->authenticationRegistry,
            new AuthEventVerifier($config, $this->policy, new Nip42Validator(new SystemClock())),
            $this->eventValidator(),
            $this->buildReevaluator($this->policy, $this->createStub(RelayEventStoreInterface::class)),
            new AuthChallengeIssuer($this->authenticationRegistry),
            new NullLogger(),
        );
    }

    private function buildReevaluator(RelayPolicyInterface $policy, RelayEventStoreInterface $eventStore): SubscriptionReevaluator
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(true);
        $admission = new SubscriptionAdmission(
            $policy,
            new RateLimitGate($rateLimiter, $policy),
            $this->subscriptionRegistry,
        );

        $synchronousExecutor = new class implements DeferredExecutorInterface {
            #[Override]
            public function defer(Closure $task): void
            {
                $task();
            }
        };

        $storedEventStreamer = new StoredEventStreamer(
            $eventStore,
            $policy,
            $this->messenger,
            $this->subscriptionRegistry,
            new SystemClock(),
            new NullLogger(),
        );

        $activator = new SubscriptionActivator(
            $admission,
            $this->subscriptionRegistry,
            $storedEventStreamer,
            $synchronousExecutor,
            new AuthChallengeIssuer($this->authenticationRegistry),
        );

        return new SubscriptionReevaluator($this->subscriptionRegistry, $activator);
    }

    public function testSuccessfulAuthentication(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://relay.example.com');

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
        $this->assertTrue($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAFrameNamingNoLiveChallengeIsRefusedWithoutVerifyingItsSignature(): void
    {
        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $policy = $this->createMock(RelayPolicyInterface::class);
        $policy->expects($this->never())->method('allowsAuthentication');

        $validator = $this->createMock(EventValidatorInterface::class);
        $validator->expects($this->never())->method('validateEvent');

        $useCase = new ProcessAuthUseCase(
            $this->authenticationRegistry,
            new AuthEventVerifier($config, $policy, new Nip42Validator(new SystemClock())),
            $validator,
            $this->buildReevaluator($policy, $this->createStub(RelayEventStoreInterface::class)),
            new AuthChallengeIssuer($this->authenticationRegistry),
            new NullLogger(),
        );

        $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $replies = $useCase->execute($this->client, $this->createAuthEvent(Challenge::fromString('never-issued'), 'wss://relay.example.com'));

        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertStringStartsWith('auth-required: ', $replies[0]->getMessage());
    }

    public function testASubscriptionRefusedOnReevaluationIsActuallyClosed(): void
    {
        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(null);
        $policy->method('isRateLimitExempt')->willReturn(true);
        $policy->method('allowSubscription')->willReturn(PolicyRejection::blocked('no longer permitted'));

        $originalFilters = new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]);
        $subscription = Subscription::create(SubscriptionIdMother::from('doomed'), $originalFilters, SubscriptionState::Live);
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription, $originalFilters);

        $useCase = new ProcessAuthUseCase(
            $this->authenticationRegistry,
            new AuthEventVerifier($config, $policy, new Nip42Validator(new SystemClock())),
            $this->eventValidator(),
            $this->buildReevaluator($policy, $this->createStub(RelayEventStoreInterface::class)),
            new AuthChallengeIssuer($this->authenticationRegistry),
            new NullLogger(),
        );

        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $replies = $useCase->execute($this->client, $this->createAuthEvent($challenge, 'wss://relay.example.com'));

        $this->assertInstanceOf(ClosedMessage::class, $replies[0]);
        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testReevaluationDoesNotSpendAFreshSubscriptionToken(): void
    {
        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(null);
        $policy->method('isRateLimitExempt')->willReturn(false);
        $policy->method('filterForClient')->willReturnCallback(
            static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters)
        );

        $originalFilters = new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]);
        $subscription = Subscription::create(SubscriptionIdMother::from('kept'), $originalFilters, SubscriptionState::Live);
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription, $originalFilters);

        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->never())->method('tryConsume');

        $activator = new SubscriptionActivator(
            new SubscriptionAdmission($policy, new RateLimitGate($rateLimiter, $policy), $this->subscriptionRegistry),
            $this->subscriptionRegistry,
            new StoredEventStreamer(
                $this->createStub(RelayEventStoreInterface::class),
                $policy,
                $this->messenger,
                $this->subscriptionRegistry,
                new SystemClock(),
                new NullLogger(),
            ),
            new AmphpDeferredExecutor(),
            new AuthChallengeIssuer($this->authenticationRegistry),
        );

        new SubscriptionReevaluator($this->subscriptionRegistry, $activator)->reevaluate($this->client);

        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testReevaluatesClientSubscriptionsOnSuccessfulAuth(): void
    {
        $sent = [];
        $this->connection->method('sendText')
            ->willReturnCallback(static function (string $json) use (&$sent): void {
                $sent[] = $json;
            });

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(null);
        $policy->method('isRateLimitExempt')->willReturn(true);
        $policy->method('canClientReceiveEvent')->willReturn(true);
        $policy->method('filterForClient')->willReturnCallback(
            static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters)
        );

        $request = $this->createNostrConnectRequest();
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn(new EventCollection([$request]));

        $originalFilters = new FilterCollection([Filter::tryFromArray([
            'kinds' => [EventKind::fromInt(EventKind::NOSTR_CONNECT)->toInt()],
            '#p' => [$this->keyPair->getPublicKey()->toHex()],
        ])]);
        $subscription = Subscription::create(SubscriptionIdMother::from('bunker'), $originalFilters, SubscriptionState::Live);
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription, $originalFilters);

        $useCase = new ProcessAuthUseCase(
            $this->authenticationRegistry,
            new AuthEventVerifier($config, $policy, new Nip42Validator(new SystemClock())),
            $this->eventValidator(),
            $this->buildReevaluator($policy, $eventStore),
            new AuthChallengeIssuer($this->authenticationRegistry),
            new NullLogger(),
        );

        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $replies = $useCase->execute($this->client, $this->createAuthEvent($challenge, 'wss://relay.example.com'));

        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertTrue($replies[0]->isAccepted());
        $this->assertTrue($this->authenticationRegistry->isAuthenticated($this->client->getId()));

        $eventMessages = array_filter($sent, static fn (string $json): bool => str_starts_with($json, '["EVENT"'));
        $this->assertNotEmpty($eventMessages, 'auth should re-run the subscription and stream the now-visible request');
    }

    public function testRejectsAuthenticationFromNonTenantPubkey(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://relay.example.com');

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(PolicyRejection::restricted('authentication is limited to relay tenants'));

        $useCase = new ProcessAuthUseCase(
            $this->authenticationRegistry,
            new AuthEventVerifier($config, $policy, new Nip42Validator(new SystemClock())),
            $this->eventValidator(),
            $this->buildReevaluator($policy, $this->createStub(RelayEventStoreInterface::class)),
            new AuthChallengeIssuer($this->authenticationRegistry),
            new NullLogger(),
        );

        $replies = $useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('restricted', $replies[0]->getMessage());
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testIssuesChallengeWhenNoneOutstanding(): void
    {
        $event = $this->createAuthEvent(Challenge::fromString('some-challenge'), 'wss://relay.example.com');

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(2, $replies);
        $this->assertInstanceOf(AuthMessage::class, $replies[0]);
        $this->assertInstanceOf(OkMessage::class, $replies[1]);
        $this->assertFalse($replies[1]->isAccepted());
        $this->assertStringContainsString('auth-required', $replies[1]->getMessage());
        $this->assertNotNull($this->authenticationRegistry->getChallenge($this->client->getId()));
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testRejectsInvalidChallenge(): void
    {
        $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent(Challenge::fromString('wrong-challenge'), 'wss://relay.example.com');

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('auth-required: Challenge does not match', $replies[0]->getMessage());
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testRejectsInvalidRelayUrl(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://wrong-relay.example.com');

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('auth-required: Relay URL does not match', $replies[0]->getMessage());
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testRejectsUnsignedAuthEventClaimingVictimPubkey(): void
    {
        $victim = KeyMother::bobPublicKey();
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $forged = EventMother::fromRumour(new Rumour(
            $victim,
            Timestamp::now(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            new TagCollection([
                Tag::tryFromArray(['relay', 'wss://relay.example.com']),
                Tag::tryFromArray(['challenge', (string) $challenge]),
            ]),
            EventContent::fromString(''),
        ));

        $replies = $this->useCase->execute($this->client, $forged);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('signature is invalid', $replies[0]->getMessage());
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
        $this->assertFalse($this->authenticationRegistry->isAuthenticatedAs($this->client->getId(), $victim));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEventWithTimestamp($challenge, 'wss://relay.example.com', time() - 700);

        $replies = $this->useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('timestamp', $replies[0]->getMessage());
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    public function testRejectsTimestampAgainstTheInjectedClockNotWallClock(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEventWithTimestamp($challenge, 'wss://relay.example.com', time());

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(null);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(time() + 601));

        $useCase = new ProcessAuthUseCase(
            $this->authenticationRegistry,
            new AuthEventVerifier($config, $policy, new Nip42Validator($clock)),
            $this->eventValidator(),
            $this->buildReevaluator($policy, $this->createStub(RelayEventStoreInterface::class)),
            new AuthChallengeIssuer($this->authenticationRegistry),
            new NullLogger(),
        );

        $replies = $useCase->execute($this->client, $event);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(OkMessage::class, $replies[0]);
        $this->assertFalse($replies[0]->isAccepted());
        $this->assertStringContainsString('timestamp', $replies[0]->getMessage());
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->client->getId()));
    }

    private function createNostrConnectRequest(): Event
    {
        $author = KeyPair::generate($this->signatureService());

        return new Rumour(
            $author->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::NOSTR_CONNECT),
            new TagCollection([Tag::tryFromArray(['p', $this->keyPair->getPublicKey()->toHex()])]),
            EventContent::fromString('encrypted-request'),
        )->sign($author, $this->signatureService());
    }

    private function createAuthEvent(Challenge $challenge, string $relayUrl): Event
    {
        return $this->createAuthEventWithTimestamp($challenge, $relayUrl, time());
    }

    private function createAuthEventWithTimestamp(Challenge $challenge, string $relayUrl, int $timestamp): Event
    {
        return new Rumour(
            $this->keyPair->getPublicKey(),
            Timestamp::fromInt($timestamp),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            new TagCollection([
                Tag::tryFromArray(['relay', $relayUrl]),
                Tag::tryFromArray(['challenge', (string) $challenge]),
            ]),
            EventContent::fromString(''),
        )->sign($this->keyPair, $this->signatureService());
    }
}
