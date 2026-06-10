<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Service\EventValidationService;
use Innis\Nostr\Core\Domain\Service\MessageSerialiserInterface;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuth\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class MessageRouterTest extends TestCase
{
    private function signatureService(): \Innis\Nostr\Core\Domain\Service\SignatureServiceInterface
    {
        return Secp256k1SignatureAdapter::create();
    }

    private MessageSerialiserInterface&Stub $serialiser;
    private RelayEventStoreInterface&Stub $eventStore;
    private RelayPolicyInterface&Stub $policy;
    private SubscriptionManager $subscriptionManager;
    private AuthenticationManager $authManager;
    private MessageRouter $router;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->serialiser = $this->createStub(MessageSerialiserInterface::class);
        $this->eventStore = $this->createStub(RelayEventStoreInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->policy->method('allowsAuthentication')->willReturn(true);
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);
        $this->authManager = new AuthenticationManager();

        $clientManager = new ClientManager(
            $this->subscriptionManager,
            $metrics,
            $logger,
        );

        $distributor = new EventDistributor(
            $this->policy,
            $this->subscriptionManager,
            $clientManager,
            $metrics,
            $logger,
        );

        $signatureService = $this->signatureService();
        $eventValidator = new EventValidationService($signatureService, new NipComplianceValidator($signatureService));

        $processEvent = new ProcessEventSubmissionUseCase(
            $this->eventStore,
            $this->policy,
            $distributor,
            $this->authManager,
            $rateLimiter,
            $metrics,
            $logger,
            $eventValidator,
        );

        $createSubscription = new CreateSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            $this->subscriptionManager,
            $this->authManager,
            $rateLimiter,
            $logger,
        );

        $closeSubscription = new CloseSubscriptionUseCase($this->subscriptionManager, $logger);

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $processAuth = new ProcessAuthUseCase(
            $this->authManager,
            $config,
            $this->policy,
            $logger,
            $eventValidator,
        );

        $countSubscription = new CountSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            $this->authManager,
            $rateLimiter,
            $logger,
        );

        $this->router = new MessageRouter(
            $processEvent,
            $createSubscription,
            $closeSubscription,
            $processAuth,
            $countSubscription,
            $this->serialiser,
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
            $this->createStub(SubscriptionLookupInterface::class),
        );
    }

    public function testRoutesEventMessage(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $event = (new Event(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::textNote(),
            TagCollection::empty(),
            EventContent::fromString('test'),
        ))->sign($keyPair, $this->signatureService());

        $this->serialiser->method('deserialiseClientMessage')->willReturn(new EventMessage($event));
        $this->eventStore->method('store')->willReturn(EventStoreOutcome::Stored);

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, '["EVENT",{}]');
    }

    public function testRoutesReqMessage(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $filters = [new Filter()];

        $this->serialiser->method('deserialiseClientMessage')->willReturn(new ReqMessage($subId, $filters));
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
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
        $this->policy->method('filterForClient')->willReturn(ScopedFilters::unchanged($filters));
        $this->eventStore->method('findByFilters')->willReturn([]);

        $this->router->route($this->client, '["REQ","sub-1",{}]');
        $this->router->route($this->client, '["CLOSE","sub-1"]');

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testRoutesAuthMessage(): void
    {
        $keyPair = KeyPair::generate($this->signatureService());
        $challenge = $this->authManager->generateChallenge($this->client->getId());

        $event = (new Event(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::clientAuth(),
            new TagCollection([
                Tag::fromArray(['relay', 'wss://relay.example.com']),
                Tag::fromArray(['challenge', $challenge]),
            ]),
            EventContent::fromString(''),
        ))->sign($keyPair, $this->signatureService());

        $this->serialiser->method('deserialiseClientMessage')->willReturn(new AuthMessage($event));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, '["AUTH",{}]');

        $this->assertTrue($this->authManager->isAuthenticated($client->getId()));
    }

    public function testRoutesCountMessage(): void
    {
        $subId = SubscriptionId::fromString('count-1');
        $filters = [new Filter()];

        $this->serialiser->method('deserialiseClientMessage')->willReturn(new CountMessage($subId, $filters));
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

        $this->router->route($client, '["COUNT","count-1",{}]');
    }

    public function testSendsNoticeForInvalidMessage(): void
    {
        $this->serialiser
            ->method('deserialiseClientMessage')
            ->willThrowException(new InvalidArgumentException('bad json'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'NOTICE' === $data[0] && str_contains((string) $data[1], 'Invalid message');
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, 'invalid');
    }

    public function testSendsNoticeForUnexpectedError(): void
    {
        $this->serialiser
            ->method('deserialiseClientMessage')
            ->willThrowException(new RuntimeException('unexpected'));

        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'NOTICE' === $data[0] && str_contains((string) $data[1], 'Internal server error');
            }));
        $client = $this->makeClient($connection);

        $this->router->route($client, '[]');
    }
}
