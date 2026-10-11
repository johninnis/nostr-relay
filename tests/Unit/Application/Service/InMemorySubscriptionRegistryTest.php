<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;

final class InMemorySubscriptionRegistryTest extends TestCase
{
    private InMemorySubscriptionRegistry $manager;

    protected function setUp(): void
    {
        $this->manager = $this->makeManager();
    }

    private function makeManager(?MetricsCollectorInterface $metrics = null): InMemorySubscriptionRegistry
    {
        return new InMemorySubscriptionRegistry(
            $metrics ?? $this->createStub(MetricsCollectorInterface::class),
            new NullLogger(),
        );
    }

    /**
     * @param list<int>|null $kinds
     */
    private function createSubscription(string $subId, ?array $kinds = null): Subscription
    {
        $filters = new FilterCollection([Filter::from(kinds: null !== $kinds ? EventKindCollection::fromInts($kinds) : null)]);

        return Subscription::create(SubscriptionIdMother::from($subId), $filters);
    }

    public function testAddSubscriptionIncrementsMetrics(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('incrementSubscriptions');
        $manager = $this->makeManager($metrics);

        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1');

        $manager->addSubscription($clientId, $subscription);
    }

    public function testReplacingASubscriptionIncrementsMetricsForEachAddition(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->exactly(2))->method('incrementSubscriptions');

        $this->replaceSubscription($this->makeManager($metrics), ClientId::fromString('client-1'));
    }

    public function testReplacingASubscriptionDecrementsMetricsForTheReplaced(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('decrementSubscriptions');

        $this->replaceSubscription($this->makeManager($metrics), ClientId::fromString('client-1'));
    }

    public function testAddSubscriptionReplacesExisting(): void
    {
        $clientId = ClientId::fromString('client-1');

        $this->replaceSubscription($this->manager, $clientId);

        $this->assertSame(1, $this->manager->getSubscriptionCountForClient($clientId));
    }

    public function testRemoveSubscriptionDecrementsMetrics(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('decrementSubscriptions');

        $this->addAndRemoveSubscription($this->makeManager($metrics), ClientId::fromString('client-1'));
    }

    public function testRemoveSubscriptionLeavesTheClientNone(): void
    {
        $clientId = ClientId::fromString('client-1');

        $this->addAndRemoveSubscription($this->manager, $clientId);

        $this->assertSame(0, $this->manager->getSubscriptionCountForClient($clientId));
    }

    public function testRemoveNonExistentSubscriptionIsNoOp(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('decrementSubscriptions');
        $manager = $this->makeManager($metrics);

        $manager->removeSubscription(
            ClientId::fromString('client-1'),
            SubscriptionIdMother::from('missing'),
        );
    }

    public function testRemoveAllForClientDecrementsMetricsForEachSubscription(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->exactly(2))->method('decrementSubscriptions');

        $this->addTwoAndRemoveAllForClient($this->makeManager($metrics), ClientId::fromString('client-1'));
    }

    public function testRemoveAllForClientCleansUpAllSubscriptions(): void
    {
        $clientId = ClientId::fromString('client-1');

        $this->addTwoAndRemoveAllForClient($this->manager, $clientId);

        $this->assertSame(0, $this->manager->getSubscriptionCountForClient($clientId));
    }

    public function testUpdateSubscriptionStateChangesState(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subId = SubscriptionIdMother::from('sub-1');
        $subscription = $this->createSubscription('sub-1');

        $this->manager->addSubscription($clientId, $subscription);
        $this->manager->updateSubscriptionState($clientId, $subId, SubscriptionState::Live);

        $updated = $this->manager->getSubscriptionsForClient($clientId)->get($subId);

        $this->assertSame(SubscriptionState::Live, $updated?->getState());
    }

    public function testGetSubscriptionsForEventReturnsMatchingByKind(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1', [EventKind::TEXT_NOTE]);

        $this->manager->addSubscription($clientId, $subscription);

        $results = $this->manager->getSubscriptionsForEvent(EventKind::fromInt(EventKind::TEXT_NOTE));

        $this->assertEquals([new SubscriptionMatch($clientId, $subscription)], $results->toArray());
    }

    public function testGetSubscriptionsForEventReturnsWildcardSubscriptions(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1');

        $this->manager->addSubscription($clientId, $subscription);

        $results = $this->manager->getSubscriptionsForEvent(EventKind::fromInt(EventKind::TEXT_NOTE));

        $this->assertCount(1, $results);
    }

    public function testGetSubscriptionsForEventExcludesNonMatchingKinds(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1', [EventKind::METADATA]);

        $this->manager->addSubscription($clientId, $subscription);

        $results = $this->manager->getSubscriptionsForEvent(EventKind::fromInt(EventKind::TEXT_NOTE));

        $this->assertCount(0, $results);
    }

    public function testGetSubscriptionsForClientReturnsCollection(): void
    {
        $clientId = ClientId::fromString('client-1');
        $this->manager->addSubscription($clientId, $this->createSubscription('sub-1'));
        $this->manager->addSubscription($clientId, $this->createSubscription('sub-2'));

        $result = $this->manager->getSubscriptionsForClient($clientId);

        $this->assertSame(2, $result->count());
    }

    public function testGetSubscriptionsForClientReturnsEmptyForUnknown(): void
    {
        $result = $this->manager->getSubscriptionsForClient(ClientId::fromString('unknown'));

        $this->assertTrue($result->isEmpty());
    }

    public function testGetAllSubscriptionsReturnsAll(): void
    {
        $this->manager->addSubscription(ClientId::fromString('c1'), $this->createSubscription('sub-1'));
        $this->manager->addSubscription(ClientId::fromString('c2'), $this->createSubscription('sub-2'));

        $result = $this->manager->getAllSubscriptions();

        $this->assertSame(2, $result->count());
    }

    public function testASubscriptionGoingLiveIsRecorded(): void
    {
        $recorded = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(static function (string|Stringable $message, array $context) use (&$recorded): void {
            $recorded[] = [(string) $message, $context];
        });
        $manager = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), $logger);
        $manager->addSubscription(ClientId::fromString('client-1'), $this->createSubscription('sub-1'));

        $manager->updateSubscriptionState(ClientId::fromString('client-1'), SubscriptionIdMother::from('sub-1'), SubscriptionState::Live);

        $this->assertContains(['Subscription now live', ['subscription_id' => 'sub-1', 'client_id' => 'client-1']], $recorded);
    }

    public function testItHoldsASubscriptionItRegistered(): void
    {
        $subscription = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $subscription);

        $this->assertTrue($this->manager->holds(ClientId::fromString('client-1'), $subscription));
    }

    public function testItNoLongerHoldsASubscriptionReplacedOnItsId(): void
    {
        $replaced = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $replaced);
        $this->manager->addSubscription(ClientId::fromString('client-1'), $this->createSubscription('sub-1'));

        $this->assertFalse($this->manager->holds(ClientId::fromString('client-1'), $replaced));
    }

    public function testItHoldsTheSubscriptionThatReplacedAnother(): void
    {
        $replacement = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $this->createSubscription('sub-1'));
        $this->manager->addSubscription(ClientId::fromString('client-1'), $replacement);

        $this->assertTrue($this->manager->holds(ClientId::fromString('client-1'), $replacement));
    }

    public function testItNoLongerHoldsARemovedSubscription(): void
    {
        $subscription = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $subscription);
        $this->manager->removeSubscription(ClientId::fromString('client-1'), $subscription->getId());

        $this->assertFalse($this->manager->holds(ClientId::fromString('client-1'), $subscription));
    }

    public function testItNoLongerHoldsASubscriptionOfAClientWhoseSubscriptionsWereAllRemoved(): void
    {
        $subscription = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $subscription);
        $this->manager->removeAllForClient(ClientId::fromString('client-1'));

        $this->assertFalse($this->manager->holds(ClientId::fromString('client-1'), $subscription));
    }

    public function testItStillHoldsASubscriptionThatWentLive(): void
    {
        $subscription = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $subscription);
        $this->manager->updateSubscriptionState(ClientId::fromString('client-1'), $subscription->getId(), SubscriptionState::Live);

        $this->assertTrue($this->manager->holds(ClientId::fromString('client-1'), $subscription));
    }

    public function testItDoesNotHoldASubscriptionForAnotherClient(): void
    {
        $subscription = $this->createSubscription('sub-1');
        $this->manager->addSubscription(ClientId::fromString('client-1'), $subscription);

        $this->assertFalse($this->manager->holds(ClientId::fromString('client-2'), $subscription));
    }

    private function replaceSubscription(InMemorySubscriptionRegistry $manager, ClientId $clientId): void
    {
        $manager->addSubscription($clientId, $this->createSubscription('sub-1', [EventKind::TEXT_NOTE]));
        $manager->addSubscription($clientId, $this->createSubscription('sub-1', [EventKind::METADATA]));
    }

    private function addAndRemoveSubscription(InMemorySubscriptionRegistry $manager, ClientId $clientId): void
    {
        $subscription = $this->createSubscription('sub-1');

        $manager->addSubscription($clientId, $subscription);
        $manager->removeSubscription($clientId, $subscription->getId());
    }

    private function addTwoAndRemoveAllForClient(InMemorySubscriptionRegistry $manager, ClientId $clientId): void
    {
        $manager->addSubscription($clientId, $this->createSubscription('sub-1'));
        $manager->addSubscription($clientId, $this->createSubscription('sub-2'));

        $manager->removeAllForClient($clientId);
    }
}
