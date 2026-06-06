<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\DTO\ScopedFilters;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CountSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private RateLimiterInterface&Stub $rateLimiter;
    private CountSubscriptionUseCase $useCase;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);

        $this->useCase = new CountSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            new AuthenticationManager(),
            $this->rateLimiter,
            new NullLogger(),
        );
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('client-1'),
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->createStub(SubscriptionLookupInterface::class),
        );
    }

    public function testSuccessfulCountReturnsCountMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
        $filters = [new Filter()];

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(42);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'COUNT' === $data[0] && 'count-1' === $data[1] && 42 === $data[2]['count'];
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);
    }

    public function testBeyondScopeSendsNoticeAndChallengeThenCount(): void
    {
        $subId = SubscriptionId::fromString('count-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped([Filter::fromArray(['kinds' => [1]])], true),
        );
        $this->eventStore->method('countByFilters')->willReturn(7);

        $sent = [];
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willReturnCallback(static function (string $json) use (&$sent): void {
            $decoded = json_decode($json, true);
            assert(is_array($decoded));
            $sent[] = $decoded;
        });
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, [new Filter()]);

        $this->assertSame('NOTICE', $sent[0][0]);
        $this->assertSame('AUTH', $sent[1][0]);
        $this->assertSame('COUNT', $sent[2][0]);
    }

    public function testPolicyViolationSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
        $filters = [new Filter()];

        $this->policy->method('allowSubscription')
            ->willThrowException(new PolicyViolationException('not allowed'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'CLOSED' === $data[0] && str_contains((string) $data[2], 'blocked');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);
    }

    public function testRateLimitSendsClosedMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
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
}
