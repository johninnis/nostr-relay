<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CountSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&MockObject $eventStore;
    private RelayPolicyInterface&MockObject $policy;
    private RateLimiterInterface&MockObject $rateLimiter;
    private CountSubscriptionUseCase $useCase;
    private RelayClient $client;
    private ClientConnectionInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(RelayEventStoreInterface::class);
        $this->policy = $this->createMock(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);

        $this->useCase = new CountSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            new AuthenticationManager(),
            $this->rateLimiter,
            new NullLogger(),
        );

        $this->connection = $this->createMock(ClientConnectionInterface::class);
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            $this->connection,
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->createMock(SubscriptionLookupInterface::class),
        );
    }

    public function testSuccessfulCountReturnsCountMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
        $filters = [new Filter()];

        $this->policy->method('filterForClient')->willReturn($filters);
        $this->eventStore->method('countByFilters')->willReturn(42);

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'COUNT' === $data[0] && 'count-1' === $data[1] && 42 === $data[2]['count'];
            }));

        $this->useCase->execute($this->client, $subId, $filters);
    }

    public function testPolicyViolationSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
        $filters = [new Filter()];

        $this->policy->method('allowSubscription')
            ->willThrowException(new PolicyViolationException('not allowed'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'blocked');
            }));

        $this->useCase->execute($this->client, $subId, $filters);
    }

    public function testAuthRequiredSendsAuthChallengeAndClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
        $filters = [new Filter()];

        $this->policy->method('allowSubscription')
            ->willThrowException(new AuthRequiredException('auth needed'));

        $sentMessages = [];
        $this->connection->method('sendText')
            ->willReturnCallback(static function (string $json) use (&$sentMessages): void {
                $sentMessages[] = json_decode($json, true);
            });

        $this->useCase->execute($this->client, $subId, $filters);

        $this->assertCount(2, $sentMessages);
        $this->assertSame('AUTH', $sentMessages[0][0]);
        $this->assertSame('CLOSED', $sentMessages[1][0]);
        $this->assertStringContainsString('auth-required', (string) $sentMessages[1][2]);
    }

    public function testRateLimitSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
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
}
