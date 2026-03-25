<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Service\MessageSerialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class MessageRouterTest extends TestCase
{
    private MessageSerialiserInterface&MockObject $serialiser;
    private RelayEventStoreInterface&MockObject $eventStore;
    private RelayPolicyInterface&MockObject $policy;
    private SubscriptionManager $subscriptionManager;
    private MessageRouter $router;
    private RelayClient $client;
    private ClientConnectionInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->serialiser = $this->createMock(MessageSerialiserInterface::class);
        $this->eventStore = $this->createMock(RelayEventStoreInterface::class);
        $this->policy = $this->createMock(RelayPolicyInterface::class);
        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);
        $subscriptionManager = $this->subscriptionManager;
        $clientManager = new ClientManager(
            $subscriptionManager,
            $metrics,
            $logger,
        );

        $distributor = new EventDistributor(
            $this->policy,
            $subscriptionManager,
            $clientManager,
            $metrics,
            $logger,
        );

        $processEvent = new ProcessEventSubmissionUseCase(
            $this->eventStore,
            $this->policy,
            $distributor,
            $rateLimiter,
            $metrics,
            $logger,
        );

        $createSubscription = new CreateSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            $subscriptionManager,
            $rateLimiter,
            $logger,
        );

        $closeSubscription = new CloseSubscriptionUseCase($subscriptionManager, $logger);

        $this->router = new MessageRouter(
            $processEvent,
            $createSubscription,
            $closeSubscription,
            $this->serialiser,
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

    public function testRoutesEventMessage(): void
    {
        $keyPair = KeyPair::generate();
        $event = (new Event(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::textNote(),
            TagCollection::empty(),
            EventContent::fromString('test'),
        ))->sign($keyPair->getPrivateKey());

        $this->serialiser->method('deserialiseClientMessage')->willReturn(new EventMessage($event));
        $this->eventStore->method('store')->willReturn(true);

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));

        $this->router->route($this->client, '["EVENT",{}]');
    }

    public function testRoutesReqMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->serialiser->method('deserialiseClientMessage')->willReturn(new ReqMessage($subId, $filters));
        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(20);
        $this->policy->method('filterForClient')->willReturn($filters);
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->router->route($this->client, '["REQ","sub-1",{}]');

        $clientId = $this->client->getId();
        $this->assertSame(1, $this->subscriptionManager->getSubscriptionCountForClient($clientId));
    }

    public function testRoutesCloseMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->serialiser->method('deserialiseClientMessage')
            ->willReturnOnConsecutiveCalls(
                new ReqMessage($subId, $filters),
                new CloseMessage($subId),
            );
        $this->policy->method('getMaxSubscriptionsPerClient')->willReturn(20);
        $this->policy->method('filterForClient')->willReturn($filters);
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->router->route($this->client, '["REQ","sub-1",{}]');
        $this->router->route($this->client, '["CLOSE","sub-1"]');

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testSendsNoticeForInvalidMessage(): void
    {
        $this->serialiser
            ->method('deserialiseClientMessage')
            ->willThrowException(new InvalidArgumentException('bad json'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'NOTICE' === $data[0] && str_contains((string) $data[1], 'Invalid message');
            }));

        $this->router->route($this->client, 'invalid');
    }

    public function testSendsNoticeForUnexpectedError(): void
    {
        $this->serialiser
            ->method('deserialiseClientMessage')
            ->willThrowException(new RuntimeException('unexpected'));

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'NOTICE' === $data[0] && str_contains((string) $data[1], 'Internal server error');
            }));

        $this->router->route($this->client, '[]');
    }
}
