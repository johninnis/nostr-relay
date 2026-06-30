<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EventDistributorTest extends TestCase
{
    private RelayPolicyInterface&Stub $policy;
    private SubscriptionManager $subscriptionManager;
    private ClientManager $clientManager;
    private MetricsCollectorInterface&MockObject $metrics;
    private EventDistributor $distributor;

    protected function setUp(): void
    {
        $this->policy = $this->createStub(RelayPolicyInterface::class);
        $this->metrics = $this->createMock(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($this->metrics, $logger);
        $this->clientManager = new ClientManager(
            $this->metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );

        $this->distributor = new EventDistributor(
            $this->policy,
            $this->subscriptionManager,
            $this->clientManager,
            new ClientMessenger($this->clientManager),
            $this->metrics,
            $logger,
        );
    }

    private function createEvent(): Event
    {
        $keyPair = KeyPair::generate(Secp256k1Signer::create());

        return new Event(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection(),
            EventContent::fromString('test content'),
        );
    }

    /**
     * @param list<int>|null $kinds
     */
    private function registerClientWithSubscription(string $subIdStr, ?array $kinds = null, ?ClientConnectionInterface $connection = null): RelayClient
    {
        $connection ??= $this->createStub(ClientConnectionInterface::class);
        $connectionInfo = new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now());
        $client = $this->clientManager->registerClient($connection, $connectionInfo);

        $filter = new Filter(kinds: null !== $kinds ? EventKindCollection::fromInts($kinds) : null);
        $subscription = Subscription::create(SubscriptionIdMother::from($subIdStr), new FilterCollection([$filter]))
            ->withState(SubscriptionState::Active);

        $this->subscriptionManager->addSubscription($client->getId(), $subscription);

        return $client;
    }

    public function testDistributeToSubscribersWithNoSubscriptions(): void
    {
        $this->metrics->expects($this->never())->method('incrementEventsSent');

        $this->distributor->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeToMatchingSubscriber(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->metrics->expects($this->once())->method('incrementEventsSent');

        $this->registerClientWithSubscription('sub-1', [EventKind::TEXT_NOTE]);

        $this->distributor->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeSkipsClientRejectedByPolicy(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(false);
        $this->metrics->expects($this->never())->method('incrementEventsSent');

        $this->registerClientWithSubscription('sub-1', [EventKind::TEXT_NOTE]);

        $this->distributor->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeSkipsNonMatchingKinds(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->metrics->expects($this->never())->method('incrementEventsSent');

        $this->registerClientWithSubscription('sub-1', [EventKind::METADATA]);

        $this->distributor->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeSkipsDisconnectedSubscriberAndContinues(): void
    {
        $this->policy->method('canClientReceiveEvent')->willReturn(true);
        $this->metrics->expects($this->once())->method('incrementEventsSent');

        $deadConnection = $this->createStub(ClientConnectionInterface::class);
        $deadConnection->method('sendText')
            ->willThrowException(ConnectionException::peerDisconnected());

        $liveConnection = $this->createMock(ClientConnectionInterface::class);
        $liveConnection->expects($this->once())->method('sendText');

        $this->registerClientWithSubscription('sub-dead', [EventKind::TEXT_NOTE], $deadConnection);
        $this->registerClientWithSubscription('sub-live', [EventKind::TEXT_NOTE], $liveConnection);

        $this->distributor->distributeToSubscribers($this->createEvent());
    }
}
