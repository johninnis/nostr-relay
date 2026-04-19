<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EventDistributorTest extends TestCase
{
    private RelayPolicyInterface&MockObject $policy;
    private SubscriptionManager $subscriptionManager;
    private ClientManager $clientManager;
    private MetricsCollectorInterface&MockObject $metrics;
    private EventDistributor $distributor;

    protected function setUp(): void
    {
        $this->policy = $this->createMock(RelayPolicyInterface::class);
        $this->metrics = $this->createMock(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($this->metrics, $logger);
        $this->clientManager = new ClientManager(
            $this->subscriptionManager,
            $this->metrics,
            $logger,
        );

        $this->distributor = new EventDistributor(
            $this->policy,
            $this->subscriptionManager,
            $this->clientManager,
            $this->metrics,
            $logger,
        );
    }

    private function createEvent(): Event
    {
        $keyPair = KeyPair::generate(Secp256k1SignatureAdapter::create());

        return new Event(
            $keyPair->getPublicKey(),
            Timestamp::now(),
            EventKind::textNote(),
            TagCollection::empty(),
            EventContent::fromString('test content'),
        );
    }

    private function registerClientWithSubscription(string $subIdStr, ?array $kinds = null): RelayClient
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connectionInfo = new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now());
        $client = $this->clientManager->registerClient($connection, $connectionInfo);

        $filter = new Filter(kinds: $kinds);
        $subscription = Subscription::create(SubscriptionId::fromString($subIdStr), [$filter])
            ->withState(SubscriptionState::ACTIVE);

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
}
