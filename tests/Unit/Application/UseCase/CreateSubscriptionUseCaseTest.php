<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CreateSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&MockObject $eventStore;
    private RelayPolicyInterface&MockObject $policy;
    private SubscriptionManager $subscriptionManager;
    private RateLimiterInterface&MockObject $rateLimiter;
    private CreateSubscriptionUseCase $useCase;
    private RelayClient $client;
    private ClientConnectionInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(RelayEventStoreInterface::class);
        $this->policy = $this->createMock(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);

        $this->useCase = new CreateSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            $this->subscriptionManager,
            $this->rateLimiter,
            $logger,
        );

        $this->connection = $this->createMock(ClientConnectionInterface::class);
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            $this->connection,
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->subscriptionManager,
        );
    }

    public function testSuccessfulSubscriptionCreation(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(20);
        $this->policy->method('filterForClient')->willReturn($filters);
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->useCase->execute($this->client, $subId, $filters);

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testPolicyViolationSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->policy->method('allowSubscription')
            ->willThrowException(new PolicyViolationException('subscription not allowed'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'blocked');
            }));

        $this->useCase->execute($this->client, $subId, $filters);

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testRateLimitSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->rateLimiter->method('checkLimit')
            ->willThrowException(RateLimitException::forKey('127.0.0.1'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'rate-limited');
            }));

        $this->useCase->execute($this->client, $subId, $filters);
    }

    public function testSubscriptionLimitSendsClosedMessage(): void
    {
        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(1);
        $this->policy->method('filterForClient')->willReturnArgument(1);
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->useCase->execute($this->client, SubscriptionId::fromString('sub-1'), [new Filter()]);

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'blocked');
            }));

        $this->useCase->execute($this->client, SubscriptionId::fromString('sub-2'), [new Filter()]);

        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }
}
