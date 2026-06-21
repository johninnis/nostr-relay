<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Closure;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Entity\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Infrastructure\Monitoring\InMemoryMetricsCollector;
use Innis\Nostr\Relay\Tests\Fixture\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ProcessAuthUseCaseTest extends TestCase
{
    private AuthenticationManager $authManager;
    private ClientManager $clientManager;
    private ProcessAuthUseCase $useCase;
    private RelayPolicyInterface $policy;
    private RelayClient $client;
    private ClientConnectionInterface&MockObject $connection;
    private KeyPair $keyPair;
    private SignatureServiceInterface $sigService;
    private SubscriptionManager $subscriptionManager;

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
        $this->authManager = new AuthenticationManager();
        $this->keyPair = KeyPair::generate($this->signatureService());

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->policy->method('allowsAuthentication')->willReturn(true);

        $this->connection = $this->createMock(ClientConnectionInterface::class);
        $this->clientManager = new ClientManager(
            $this->createStub(SubscriptionLookupInterface::class),
            new InMemoryMetricsCollector(),
            new NullLogger(),
        );
        $this->client = $this->clientManager->registerClient(
            $this->connection,
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
        );

        $this->subscriptionManager = new SubscriptionManager(new InMemoryMetricsCollector(), new NullLogger());

        $this->useCase = new ProcessAuthUseCase(
            $this->authManager,
            $config,
            $this->policy,
            new NullLogger(),
            $this->eventValidator(),
            $this->clientManager,
            $this->subscriptionManager,
            $this->buildCreateSubscription($this->policy, $this->createStub(RelayEventStoreInterface::class)),
        );
    }

    private function buildCreateSubscription(RelayPolicyInterface $policy, RelayEventStoreInterface $eventStore): CreateSubscriptionUseCase
    {
        $admission = new SubscriptionAdmission(
            $policy,
            $this->createStub(RateLimiterInterface::class),
            $this->authManager,
            $this->clientManager,
        );

        $synchronousExecutor = new class implements DeferredExecutorInterface {
            public function defer(Closure $task): void
            {
                $task();
            }
        };

        return new CreateSubscriptionUseCase(
            $eventStore,
            $policy,
            $this->subscriptionManager,
            $admission,
            $this->clientManager,
            $synchronousExecutor,
            new NullLogger(),
        );
    }

    public function testSuccessfulAuthentication(): void
    {
        $challenge = $this->authManager->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertTrue($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testReevaluatesClientSubscriptionsOnSuccessfulAuth(): void
    {
        $sent = [];
        $this->connection->expects($this->atLeastOnce())->method('sendText')
            ->willReturnCallback(static function (string $json) use (&$sent): void {
                $sent[] = $json;
            });

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(true);
        $policy->method('isRateLimitExempt')->willReturn(true);
        $policy->method('canClientReceiveEvent')->willReturn(true);
        $policy->method('filterForClient')->willReturnCallback(
            static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters)
        );

        $request = $this->createNostrConnectRequest();
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn([$request]);

        $originalFilters = new FilterCollection([Filter::fromArray([
            'kinds' => [EventKind::nostrConnect()->toInt()],
            '#p' => [$this->keyPair->getPublicKey()->toHex()],
        ])]);
        $subscription = Subscription::create(SubscriptionIdMother::from('bunker'), $originalFilters, SubscriptionState::LIVE);
        $this->subscriptionManager->addSubscription($this->client->getId(), $subscription, $originalFilters);

        $useCase = new ProcessAuthUseCase(
            $this->authManager,
            $config,
            $policy,
            new NullLogger(),
            $this->eventValidator(),
            $this->clientManager,
            $this->subscriptionManager,
            $this->buildCreateSubscription($policy, $eventStore),
        );

        $challenge = $this->authManager->getOrCreateChallenge($this->client->getId());
        $useCase->execute($this->client, $this->createAuthEvent($challenge, 'wss://relay.example.com'));

        $this->assertTrue($this->authManager->isAuthenticated($this->client->getId()));

        $eventMessages = array_filter($sent, static fn (string $json): bool => str_starts_with($json, '["EVENT"'));
        $this->assertNotEmpty($eventMessages, 'auth should re-run the subscription and stream the now-visible request');
    }

    public function testRejectsAuthenticationFromNonTenantPubkey(): void
    {
        $challenge = $this->authManager->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://relay.example.com');

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(false);

        $useCase = new ProcessAuthUseCase(
            $this->authManager,
            $config,
            $policy,
            new NullLogger(),
            $this->eventValidator(),
            $this->clientManager,
            $this->subscriptionManager,
            $this->buildCreateSubscription($policy, $this->createStub(RelayEventStoreInterface::class)),
        );

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'restricted');
            }));

        $useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testIssuesChallengeWhenNoneOutstanding(): void
    {
        $event = $this->createAuthEvent('some-challenge', 'wss://relay.example.com');

        $sent = [];
        $this->connection->expects($this->exactly(2))->method('sendText')
            ->willReturnCallback(static function (string $json) use (&$sent): void {
                $decoded = json_decode($json, true);
                assert(is_array($decoded));
                $sent[] = $decoded;
            });

        $this->useCase->execute($this->client, $event);

        $this->assertSame('AUTH', $sent[0][0]);
        $this->assertSame('OK', $sent[1][0]);
        $this->assertFalse($sent[1][2]);
        $this->assertStringContainsString('auth-required', (string) $sent[1][3]);
        $this->assertNotNull($this->authManager->getChallenge($this->client->getId()));
        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsInvalidChallenge(): void
    {
        $this->authManager->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent('wrong-challenge', 'wss://relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'invalid challenge');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsInvalidRelayUrl(): void
    {
        $challenge = $this->authManager->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://wrong-relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'invalid relay URL');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsUnsignedAuthEventClaimingVictimPubkey(): void
    {
        $victim = KeyPair::generate($this->signatureService())->getPublicKey();
        $challenge = $this->authManager->getOrCreateChallenge($this->client->getId());

        $forged = Event::fromArray([
            'id' => str_repeat('a', 64),
            'pubkey' => $victim->toHex(),
            'created_at' => time(),
            'kind' => EventKind::clientAuth()->toInt(),
            'tags' => [
                ['relay', 'wss://relay.example.com'],
                ['challenge', $challenge],
            ],
            'content' => '',
            'sig' => '',
        ]) ?? throw new RuntimeException('Invalid forged event');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'signature is invalid');
            }));

        $this->useCase->execute($this->client, $forged);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
        $this->assertFalse($this->authManager->isAuthenticatedAs($this->client->getId(), $victim));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $challenge = $this->authManager->getOrCreateChallenge($this->client->getId());
        $event = $this->createAuthEventWithTimestamp($challenge, 'wss://relay.example.com', time() - 700);

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'timestamp');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    private function createNostrConnectRequest(): Event
    {
        $author = KeyPair::generate($this->signatureService());

        return (new Event(
            $author->getPublicKey(),
            Timestamp::now(),
            EventKind::nostrConnect(),
            new TagCollection([Tag::fromArray(['p', $this->keyPair->getPublicKey()->toHex()])]),
            EventContent::fromString('encrypted-request'),
        ))->sign($author, $this->signatureService());
    }

    private function createAuthEvent(string $challenge, string $relayUrl): Event
    {
        return $this->createAuthEventWithTimestamp($challenge, $relayUrl, time());
    }

    private function createAuthEventWithTimestamp(string $challenge, string $relayUrl, int $timestamp): Event
    {
        return (new Event(
            $this->keyPair->getPublicKey(),
            Timestamp::fromInt($timestamp),
            EventKind::clientAuth(),
            new TagCollection([
                Tag::fromArray(['relay', $relayUrl]),
                Tag::fromArray(['challenge', $challenge]),
            ]),
            EventContent::fromString(''),
        ))->sign($this->keyPair, $this->signatureService());
    }
}
