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
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\EventAudience;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientConnection;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EventDistributorTest extends TestCase
{
    public function testDistributeToSubscribersWithNoSubscriptions(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('incrementEventsSent');

        $this->distributor($metrics, [])->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeToMatchingSubscriber(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsSent');

        $this->distributor($metrics, ['sub-1' => $this->createStub(ClientConnectionInterface::class)])
            ->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeSkipsClientRejectedByPolicy(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('incrementEventsSent');

        $this->distributor($metrics, ['sub-1' => $this->createStub(ClientConnectionInterface::class)], policyAdmits: false)
            ->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeSkipsNonMatchingKinds(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('incrementEventsSent');

        $this->distributor($metrics, ['sub-1' => $this->createStub(ClientConnectionInterface::class)], kinds: [EventKind::METADATA])
            ->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeSkipsDisconnectedSubscriberAndContinues(): void
    {
        $liveConnection = $this->createMock(ClientConnectionInterface::class);
        $liveConnection->expects($this->once())->method('sendText');

        $this->distributor($this->createStub(MetricsCollectorInterface::class), [
            'sub-dead' => $this->disconnectedConnection(),
            'sub-live' => $liveConnection,
        ])->distributeToSubscribers($this->createEvent());
    }

    public function testDistributeCountsOnlyTheDeliveryThatReachedALiveSubscriber(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementEventsSent');

        $this->distributor($metrics, [
            'sub-dead' => $this->disconnectedConnection(),
            'sub-live' => $this->createStub(ClientConnectionInterface::class),
        ])->distributeToSubscribers($this->createEvent());
    }

    public function testEachSubscriberReceivesTheEventsOwnEncodingUnderItsOwnSubscriptionId(): void
    {
        $first = new RecordingClientConnection();
        $second = new RecordingClientConnection();
        $event = $this->createEvent();

        $this->distributor($this->createStub(MetricsCollectorInterface::class), ['sub-1' => $first, 'sub-2' => $second])
            ->distributeToSubscribers($event);

        $this->assertSame(
            [['["EVENT","sub-1",'.$event->toJson().']'], ['["EVENT","sub-2",'.$event->toJson().']']],
            [$first->sentFrames(), $second->sentFrames()],
        );
    }

    public function testEachDeliveryToASubscriberIsCountedAsAnEventSent(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->exactly(2))->method('incrementEventsSent');

        $this->distributor($metrics, ['sub-1' => new RecordingClientConnection(), 'sub-2' => new RecordingClientConnection()])
            ->distributeToSubscribers($this->createEvent());
    }

    private function createEvent(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('test content'),
            new TagCollection(),
        ));
    }

    private function disconnectedConnection(): ClientConnectionInterface
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('sendText')->willThrowException(ConnectionException::peerDisconnected());

        return $connection;
    }

    /**
     * @param array<string, ClientConnectionInterface> $connectionsBySubscriptionId
     * @param list<int>                                $kinds
     */
    private function distributor(
        MetricsCollectorInterface $metrics,
        array $connectionsBySubscriptionId,
        array $kinds = [EventKind::TEXT_NOTE],
        bool $policyAdmits = true,
    ): EventDistributor {
        $logger = new NullLogger();
        $clientRegistry = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), $logger);
        $subscriptionRegistry = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), $logger);
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts($kinds))]);

        foreach ($connectionsBySubscriptionId as $subscriptionId => $connection) {
            $client = $clientRegistry->registerClient(
                $connection,
                new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
            );
            $subscriptionRegistry->addSubscription(
                $client->getId(),
                Subscription::create(SubscriptionIdMother::from($subscriptionId), $filters)->withState(SubscriptionState::Active),
            );
        }

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn($policyAdmits);

        return new EventDistributor(
            new EventAudience(new DeliveryGate($policy, new GiftWrapFilterRules()), $subscriptionRegistry, $clientRegistry),
            new ClientMessenger($clientRegistry),
            $logger,
        );
    }
}
