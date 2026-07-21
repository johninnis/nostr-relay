<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CreateSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private RateLimiterInterface&Stub $rateLimiter;
    private bool $rateLimited = false;
    private InMemoryClientRegistry $clientRegistry;
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private CreateSubscriptionUseCase $useCase;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->rateLimiter->method('tryConsume')->willReturnCallback(fn (): bool => !$this->rateLimited);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientRegistry = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), $logger);
        $messenger = new ClientMessenger($this->clientRegistry);
        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $admission = new SubscriptionAdmission($this->policy, $this->rateLimiter, $this->subscriptionRegistry);
        $storedEventStreamer = new StoredEventStreamer($this->eventStore, $this->policy, $messenger, $this->subscriptionRegistry, $logger);
        $activator = new SubscriptionActivator(
            $admission,
            $this->subscriptionRegistry,
            $storedEventStreamer,
            new AmphpDeferredExecutor(),
            new AuthChallengeIssuer($this->authenticationRegistry),
        );

        $this->useCase = new CreateSubscriptionUseCase(
            $activator,
            $this->subscriptionRegistry,
            $logger,
        );

        $this->client = $this->makeClient();
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientRegistry->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testSuccessfulSubscriptionCreation(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $replies = $this->useCase->execute($this->client, $subId, $filters);

        $this->assertSame([], $replies);
        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testBeyondScopeReturnsNoticeAndChallenge(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $replies = $this->useCase->execute($this->client, $subId, new FilterCollection([new Filter()]));

        $this->assertInstanceOf(NoticeMessage::class, $replies[0]);
        $this->assertInstanceOf(AuthMessage::class, $replies[1]);
    }

    public function testBeyondScopeReissuesChallengeEvenWhenOneAlreadyExists(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $this->authenticationRegistry->getOrCreateChallenge($this->client->getId());

        $replies = $this->useCase->execute($this->client, $subId, new FilterCollection([new Filter()]));

        $this->assertInstanceOf(AuthMessage::class, $replies[1], 'a beyond-scope request must re-issue an AUTH challenge even if one was already issued earlier');
    }

    public function testFullyOutOfScopeSubscriptionIsCreatedNotRejected(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::scoped(new FilterCollection(), true));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $replies = $this->useCase->execute($this->client, $subId, new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues(['ff']))]));

        // A fully-out-of-scope request is scoped down and offered a challenge, not rejected: the subscription is still created.
        $this->assertInstanceOf(NoticeMessage::class, $replies[0]);
        $this->assertInstanceOf(AuthMessage::class, $replies[1]);
        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testPolicyViolationReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->policy->method('allowSubscription')
            ->willReturn(PolicyRejection::blocked('subscription not allowed'));

        $replies = $this->useCase->execute($this->client, $subId, $filters);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(ClosedMessage::class, $replies[0]);
        $this->assertStringContainsString('blocked', $replies[0]->getMessage());
        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testRateLimitReturnsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->rateLimited = true;

        $replies = $this->useCase->execute($this->client, $subId, $filters);

        $this->assertCount(1, $replies);
        $this->assertInstanceOf(ClosedMessage::class, $replies[0]);
        $this->assertStringContainsString('rate-limited', $replies[0]->getMessage());
    }

    public function testSubscriptionLimitReturnsClosedMessage(): void
    {
        $this->policy->method('allowSubscription')
            ->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters, int $currentSubscriptionCount): ?PolicyRejection => $currentSubscriptionCount >= 1
                ? PolicyRejection::blocked('too many subscriptions (max 1)')
                : null);
        $this->policy->method('filterForClient')
            ->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $client = $this->makeClient();

        $replies = [
            ...$this->useCase->execute($client, SubscriptionIdMother::from('sub-1'), new FilterCollection([new Filter()])),
            ...$this->useCase->execute($client, SubscriptionIdMother::from('sub-2'), new FilterCollection([new Filter()])),
        ];

        $closed = array_values(array_filter($replies, static fn (RelayMessage $message): bool => $message instanceof ClosedMessage));
        $this->assertCount(1, $closed);
        $this->assertStringContainsString('blocked', $closed[0]->getMessage());
        $this->assertSame(1, $this->subscriptionRegistry->getSubscriptionCountForClient($client->getId()));
    }
}
