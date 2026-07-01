<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
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
    private InMemorySubscriptionRegistry $subscriptionManager;
    private RateLimiterInterface&Stub $rateLimiter;
    private InMemoryClientRegistry $clientManager;
    private InMemoryAuthenticationRegistry $authManager;
    private CreateSubscriptionUseCase $useCase;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientManager = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), $logger);
        $messenger = new ClientMessenger($this->clientManager);
        $this->authManager = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $admission = new SubscriptionAdmission($this->policy, $this->rateLimiter, $this->authManager, $messenger, $this->subscriptionManager);
        $storedEventStreamer = new StoredEventStreamer($this->eventStore, $this->policy, $messenger, $this->subscriptionManager, $logger);

        $this->useCase = new CreateSubscriptionUseCase(
            $admission,
            $this->subscriptionManager,
            $storedEventStreamer,
            $messenger,
            new AmphpDeferredExecutor(),
            $logger,
        );

        $this->client = $this->makeClient();
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientManager->registerClient(
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

        $this->useCase->execute($this->client, $subId, $filters);

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testBeyondScopeSendsNoticeAndChallenge(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::fromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $decoded = json_decode($json, true);
            assert(is_array($decoded));
            $sent[] = $decoded;
        });
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, new FilterCollection([new Filter()]));

        $types = array_map(static fn (array $message): string => (string) $message[0], $sent);
        $this->assertContains('NOTICE', $types);
        $this->assertContains('AUTH', $types);
    }

    public function testBeyondScopeReissuesChallengeEvenWhenOneAlreadyExists(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::fromArray(['kinds' => [1]])]), true),
        );
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $decoded = json_decode($json, true);
            assert(is_array($decoded));
            $sent[] = $decoded;
        });
        $client = $this->makeClient($connection);

        $this->authManager->getOrCreateChallenge($client->getId());

        $this->useCase->execute($client, $subId, new FilterCollection([new Filter()]));

        $types = array_map(static fn (array $message): string => (string) $message[0], $sent);
        $this->assertContains('AUTH', $types, 'a beyond-scope request must re-issue an AUTH challenge even if one was already issued earlier');
    }

    public function testFullyOutOfScopeSubscriptionIsCreatedNotRejected(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::scoped(new FilterCollection(), true));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $this->useCase->execute($this->client, $subId, new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues(['ff']))]));

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testPolicyViolationSendsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->policy->method('allowSubscription')
            ->willThrowException(new PolicyViolationException('subscription not allowed'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = ClosedMessage::fromJson($json);

                return null !== $message && str_contains($message->getMessage(), 'blocked');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($client->getId()));
    }

    public function testRateLimitSendsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $filters = new FilterCollection([new Filter()]);

        $this->rateLimiter->method('checkLimit')
            ->willThrowException(RateLimitException::forKey('127.0.0.1'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = ClosedMessage::fromJson($json);

                return null !== $message && str_contains($message->getMessage(), 'rate-limited');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);
    }

    public function testSubscriptionLimitSendsClosedMessage(): void
    {
        $this->policy->method('allowSubscription')
            ->willReturnCallback(static function (RelayClient $client, FilterCollection $filters, int $currentSubscriptionCount): void {
                if ($currentSubscriptionCount >= 1) {
                    throw new PolicyViolationException('too many subscriptions (max 1)');
                }
            });
        $this->policy->method('filterForClient')
            ->willReturnCallback(static fn (RelayClient $client, FilterCollection $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn(new EventCollection([]));

        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $sent[] = $json;
        });
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, SubscriptionIdMother::from('sub-1'), new FilterCollection([new Filter()]));
        $this->useCase->execute($client, SubscriptionIdMother::from('sub-2'), new FilterCollection([new Filter()]));

        $closed = array_values(array_filter(array_map(ClosedMessage::fromJson(...), $sent)));
        $this->assertNotEmpty($closed);
        $this->assertStringContainsString('blocked', $closed[0]->getMessage());
        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($client->getId()));
    }
}
