<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\DTO\ScopedFilters;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CreateSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private SubscriptionManager $subscriptionManager;
    private RateLimiterInterface&Stub $rateLimiter;
    private CreateSubscriptionUseCase $useCase;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);

        $this->useCase = new CreateSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            $this->subscriptionManager,
            new AuthenticationManager(),
            $this->rateLimiter,
            $logger,
        );

        $this->client = $this->makeClient();
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('client-1'),
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->subscriptionManager,
        );
    }

    public function testSuccessfulSubscriptionCreation(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(20);
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->useCase->execute($this->client, $subId, $filters);

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testBeyondScopeSendsNoticeAndChallenge(): void
    {
        $subId = SubscriptionId::fromString('sub-1');

        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(20);
        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped([Filter::fromArray(['kinds' => [1]])], true),
        );
        $this->eventStore->method('findByFilters')->willReturn([]);

        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $decoded = json_decode($json, true);
            assert(is_array($decoded));
            $sent[] = $decoded;
        });
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, [new Filter()]);

        $types = array_map(static fn (array $message): string => (string) $message[0], $sent);
        $this->assertContains('NOTICE', $types);
        $this->assertContains('AUTH', $types);
    }

    public function testFullyOutOfScopeSubscriptionIsCreatedNotRejected(): void
    {
        $subId = SubscriptionId::fromString('sub-1');

        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(20);
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::scoped([], true));
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->useCase->execute($this->client, $subId, [new Filter(authors: ['ff'])]);

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testPolicyViolationSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->policy->method('allowSubscription')
            ->willThrowException(new PolicyViolationException('subscription not allowed'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'blocked');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($client->getId()));
    }

    public function testRateLimitSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->rateLimiter->method('checkLimit')
            ->willThrowException(RateLimitException::forKey('127.0.0.1'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'rate-limited');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);
    }

    public function testSubscriptionLimitSendsClosedMessage(): void
    {
        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(1);
        $this->policy->method('filterForClient')
            ->willReturnCallback(static fn (RelayClient $client, array $filters): ScopedFilters => ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->useCase->execute($this->client, SubscriptionId::fromString('sub-1'), [new Filter()]);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'blocked');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, SubscriptionId::fromString('sub-2'), [new Filter()]);

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($client->getId()));
    }
}
