<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessEventSubmissionUseCaseTest extends TestCase
{
    private RelayEventStoreInterface&MockObject $eventStore;
    private RelayPolicyInterface&MockObject $policy;
    private RateLimiterInterface&MockObject $rateLimiter;
    private MetricsCollectorInterface&MockObject $metrics;
    private ProcessEventSubmissionUseCase $useCase;
    private RelayClient $client;
    private ClientConnectionInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->eventStore = $this->createMock(RelayEventStoreInterface::class);
        $this->policy = $this->createMock(RelayPolicyInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->metrics = $this->createMock(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $subscriptionManager = new SubscriptionManager($this->metrics, $logger);
        $clientManager = new ClientManager(
            $subscriptionManager,
            $this->metrics,
            $logger,
        );

        $distributor = new EventDistributor(
            $this->policy,
            $subscriptionManager,
            $clientManager,
            $this->metrics,
            $logger,
        );

        $this->useCase = new ProcessEventSubmissionUseCase(
            $this->eventStore,
            $this->policy,
            $distributor,
            $this->rateLimiter,
            $this->metrics,
            $logger,
        );

        $this->connection = $this->createMock(ClientConnectionInterface::class);
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            $this->connection,
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->createMock(SubscriptionLookupInterface::class),
        );
    }

    private function createSignedEvent(): Event
    {
        $keyPair = KeyPair::generate();

        return (new Event(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::textNote(),
            TagCollection::empty(),
            EventContent::fromString('hello world'),
        ))->sign($keyPair->getPrivateKey());
    }

    public function testSuccessfulEventStoreAndDistribute(): void
    {
        $event = $this->createSignedEvent();

        $this->eventStore->method('store')->willReturn(true);
        $this->metrics->expects($this->once())->method('incrementEventsReceived');
        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));

        $this->useCase->execute($this->client, $event);
    }

    public function testDuplicateEventSendsNotOk(): void
    {
        $event = $this->createSignedEvent();

        $this->eventStore->method('store')->willReturn(false);
        $this->metrics->expects($this->never())->method('incrementEventsReceived');
        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'duplicate');
            }));

        $this->useCase->execute($this->client, $event);
    }

    public function testPolicyViolationSendsBlockedMessage(): void
    {
        $event = $this->createSignedEvent();

        $this->policy->method('allowEventSubmission')
            ->willThrowException(new PolicyViolationException('not allowed'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'blocked');
            }));

        $this->useCase->execute($this->client, $event);
    }

    public function testRateLimitSendsRateLimitedMessage(): void
    {
        $event = $this->createSignedEvent();

        $this->rateLimiter->method('checkLimit')
            ->willThrowException(RateLimitException::forKey('127.0.0.1'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'rate-limited');
            }));

        $this->useCase->execute($this->client, $event);
    }
}
