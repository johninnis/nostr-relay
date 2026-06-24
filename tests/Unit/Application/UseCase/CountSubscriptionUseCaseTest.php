<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CountSubscriptionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private RateLimiterInterface&Stub $rateLimiter;
    private ClientManager $clientManager;
    private CountSubscriptionUseCase $useCase;

    protected function setUp(): void
    {
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createStub(RateLimiterInterface::class);
        $this->clientManager = new ClientManager(
            $this->createStub(MetricsCollectorInterface::class),
            new NullLogger(),
        );
        $messenger = new ClientMessenger($this->clientManager);

        $admission = new SubscriptionAdmission(
            $this->policy,
            $this->rateLimiter,
            new AuthenticationManager(new NativeRandomBytesGenerator()),
            $messenger,
            $this->createStub(SubscriptionLookupInterface::class),
        );

        $this->useCase = new CountSubscriptionUseCase(
            $this->eventStore,
            $admission,
            $messenger,
            new NullLogger(),
        );
    }

    private function makeClient(?ClientConnectionInterface $connection = null): RelayClient
    {
        return $this->clientManager->registerClient(
            $connection ?? $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testSuccessfulCountReturnsCountMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([new Filter()]);

        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('countByFilters')->willReturn(42);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = CountMessage::fromJson($json);

                return null !== $message && 'count-1' === (string) $message->getSubscriptionId() && 42 === $message->getCount();
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);
    }

    public function testBeyondScopeSendsNoticeAndChallengeThenCount(): void
    {
        $subId = SubscriptionIdMother::from('count-1');

        $this->policy->method('filterForClient')->willReturn(
            ScopedFilters::scoped(new FilterCollection([Filter::fromArray(['kinds' => [1]])]), true),
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

        $this->useCase->execute($client, $subId, new FilterCollection([new Filter()]));

        $this->assertSame('NOTICE', $sent[0][0]);
        $this->assertSame('AUTH', $sent[1][0]);
        $this->assertSame('COUNT', $sent[2][0]);
    }

    public function testPolicyViolationSendsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
        $filters = new FilterCollection([new Filter()]);

        $this->policy->method('allowSubscription')
            ->willThrowException(new PolicyViolationException('not allowed'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $message = ClosedMessage::fromJson($json);

                return null !== $message && str_contains($message->getMessage(), 'blocked');
            }));
        $client = $this->makeClient($connection);

        $this->useCase->execute($client, $subId, $filters);
    }

    public function testRateLimitSendsClosedMessage(): void
    {
        $subId = SubscriptionIdMother::from('count-1');
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
}
