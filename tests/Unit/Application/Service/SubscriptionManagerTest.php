<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SubscriptionManagerTest extends TestCase
{
    private SubscriptionManager $manager;

    protected function setUp(): void
    {
        $this->manager = $this->makeManager();
    }

    private function makeManager(?MetricsCollectorInterface $metrics = null): SubscriptionManager
    {
        return new SubscriptionManager(
            $metrics ?? $this->createStub(MetricsCollectorInterface::class),
            new NullLogger(),
        );
    }

    private function createSubscription(string $subId, ?array $kinds = null): Subscription
    {
        $filters = [new Filter(kinds: $kinds)];

        return Subscription::create(SubscriptionId::fromString($subId), $filters);
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

    public function testAddSubscriptionReplacesExisting(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->exactly(2))->method('incrementSubscriptions');
        $metrics->expects($this->once())->method('decrementSubscriptions');
        $manager = $this->makeManager($metrics);

        $clientId = ClientId::fromString('client-1');
        $sub1 = $this->createSubscription('sub-1', [EventKind::TEXT_NOTE]);
        $sub2 = $this->createSubscription('sub-1', [EventKind::METADATA]);

        $manager->addSubscription($clientId, $sub1);
        $manager->addSubscription($clientId, $sub2);

        $this->assertSame(1, $manager->getSubscriptionCountForClient($clientId));
    }

    public function testRemoveSubscriptionDecrementsMetrics(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->once())->method('decrementSubscriptions');
        $manager = $this->makeManager($metrics);

        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1');

        $manager->addSubscription($clientId, $subscription);
        $manager->removeSubscription($clientId, $subscription->getId());

        $this->assertSame(0, $manager->getSubscriptionCountForClient($clientId));
    }

    public function testRemoveNonExistentSubscriptionIsNoOp(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->never())->method('decrementSubscriptions');
        $manager = $this->makeManager($metrics);

        $manager->removeSubscription(
            ClientId::fromString('client-1'),
            SubscriptionId::fromString('missing'),
        );
    }

    public function testRemoveAllForClientCleansUpAllSubscriptions(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $metrics->expects($this->exactly(2))->method('decrementSubscriptions');
        $manager = $this->makeManager($metrics);

        $clientId = ClientId::fromString('client-1');
        $manager->addSubscription($clientId, $this->createSubscription('sub-1'));
        $manager->addSubscription($clientId, $this->createSubscription('sub-2'));

        $manager->removeAllForClient($clientId);

        $this->assertSame(0, $manager->getSubscriptionCountForClient($clientId));
    }

    public function testUpdateSubscriptionStateChangesState(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subId = SubscriptionId::fromString('sub-1');
        $subscription = $this->createSubscription('sub-1');

        $this->manager->addSubscription($clientId, $subscription);
        $this->manager->updateSubscriptionState($clientId, $subId, SubscriptionState::LIVE);

        $result = $this->manager->getSubscriptionsForClient($clientId);
        $updated = $result->get($subId);
        $this->assertNotNull($updated);
        $this->assertSame(SubscriptionState::LIVE, $updated->getState());
    }

    public function testGetSubscriptionsForEventReturnsMatchingByKind(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1', [EventKind::TEXT_NOTE]);

        $this->manager->addSubscription($clientId, $subscription);

        $results = $this->manager->getSubscriptionsForEvent(EventKind::TEXT_NOTE);

        $this->assertCount(1, $results);
        $this->assertTrue($clientId->equals($results[0]->getClientId()));
    }

    public function testGetSubscriptionsForEventReturnsWildcardSubscriptions(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1');

        $this->manager->addSubscription($clientId, $subscription);

        $results = $this->manager->getSubscriptionsForEvent(EventKind::TEXT_NOTE);

        $this->assertCount(1, $results);
    }

    public function testGetSubscriptionsForEventExcludesNonMatchingKinds(): void
    {
        $clientId = ClientId::fromString('client-1');
        $subscription = $this->createSubscription('sub-1', [EventKind::METADATA]);

        $this->manager->addSubscription($clientId, $subscription);

        $results = $this->manager->getSubscriptionsForEvent(EventKind::TEXT_NOTE);

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

    public function testImplementsSubscriptionLookupInterface(): void
    {
        $this->assertInstanceOf(
            \Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface::class,
            $this->manager,
        );
    }

    public function testRecordsAndReturnsOriginalFilters(): void
    {
        $clientId = ClientId::fromString('client-1');
        $originalFilters = [new Filter(kinds: [EventKind::TEXT_NOTE])];

        $this->manager->addSubscription($clientId, $this->createSubscription('sub-1'), $originalFilters);

        $this->assertSame(
            $originalFilters,
            $this->manager->getOriginalFilters($clientId, SubscriptionId::fromString('sub-1')),
        );
    }

    public function testGetOriginalFiltersDefaultsToEmptyWhenUnknown(): void
    {
        $this->assertSame(
            [],
            $this->manager->getOriginalFilters(ClientId::fromString('client-1'), SubscriptionId::fromString('missing')),
        );
    }

    public function testGetSubscriptionIdsForClientReturnsSubscriptionIds(): void
    {
        $clientId = ClientId::fromString('client-1');
        $this->manager->addSubscription($clientId, $this->createSubscription('sub-1'));
        $this->manager->addSubscription($clientId, $this->createSubscription('sub-2'));

        $ids = array_map(
            static fn (SubscriptionId $id): string => (string) $id,
            $this->manager->getSubscriptionIdsForClient($clientId),
        );
        sort($ids);

        $this->assertSame(['sub-1', 'sub-2'], $ids);
    }

    public function testRemoveSubscriptionClearsOriginalFilters(): void
    {
        $clientId = ClientId::fromString('client-1');
        $originalFilters = [new Filter(kinds: [EventKind::TEXT_NOTE])];
        $this->manager->addSubscription($clientId, $this->createSubscription('sub-1'), $originalFilters);

        $this->manager->removeSubscription($clientId, SubscriptionId::fromString('sub-1'));

        $this->assertSame([], $this->manager->getOriginalFilters($clientId, SubscriptionId::fromString('sub-1')));
    }
}
