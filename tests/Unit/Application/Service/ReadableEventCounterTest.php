<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\ReadableEventCounter;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadableEventCounterTest extends TestCase
{
    public function testARequestWhoseFiltersAllNameKindsIsCountedAsTheStoreCountsIt(): void
    {
        $this->assertEquals(EventCount::exact(7), $this->counter(EventCount::exact(7), EventCount::exact(3))->count(self::filters(['kinds' => [1]])));
    }

    public function testGiftWrapsAreNotCountedForARequestNamingNoKind(): void
    {
        $this->assertEquals(EventCount::exact(4), $this->counter(EventCount::exact(7), EventCount::exact(3))->count(self::filters([])));
    }

    public function testGiftWrapsAreNotCountedForARequestNamingAnotherKeyWithoutAKind(): void
    {
        $filters = self::filters(['#p' => [KeyMother::bobPublicKey()->toHex()]]);

        $this->assertEquals(EventCount::exact(4), $this->counter(EventCount::exact(7), EventCount::exact(3))->count($filters));
    }

    public function testTheGiftWrapsSubtractedAreTheSameFiltersConfinedToTheGiftWrapKinds(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->exactly(2))->method('countByFilters')->willReturnCallback(
            static fn (FilterCollection $filters): EventCount => null === $filters->toArray()[0]->getKinds()
                ? EventCount::exact(7)
                : ([EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP] === $filters->toArray()[0]->getKinds()->toInts() ? EventCount::exact(3) : throw new RuntimeException('unexpected filters')),
        );

        new ReadableEventCounter($eventStore, new GiftWrapFilterRules())->count(self::filters([]));
    }

    public function testTheWrapsAFilterNamingTheirKindSelectsAreCountedBesideAFilterNamingNone(): void
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('countByFilters')->willReturnCallback(static fn (FilterCollection $filters): EventCount => match (json_encode($filters->toJsonArray())) {
            json_encode([(object) [], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]]) => EventCount::exact(10),
            json_encode([['kinds' => [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP]], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]]) => EventCount::exact(4),
            json_encode([['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]]) => EventCount::exact(1),
            default => throw new RuntimeException('unexpected filters'),
        });

        $filters = new FilterCollection([
            Filter::tryFromArray([]) ?? throw new RuntimeException('filter did not parse'),
            Filter::tryFromArray(['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]) ?? throw new RuntimeException('filter did not parse'),
        ]);

        $this->assertEquals(EventCount::exact(7), new ReadableEventCounter($eventStore, new GiftWrapFilterRules())->count($filters));
    }

    public function testACountIsApproximateWhenTheGiftWrapCountIs(): void
    {
        $this->assertTrue($this->counter(EventCount::exact(7), EventCount::approximate(3))->count(self::filters([]))->isApproximate());
    }

    public function testACountNeverFallsBelowZero(): void
    {
        $this->assertSame(0, $this->counter(EventCount::approximate(2), EventCount::approximate(3))->count(self::filters([]))->toInt());
    }

    private function counter(EventCount $total, EventCount $giftWraps): ReadableEventCounter
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('countByFilters')->willReturnCallback(
            static fn (FilterCollection $filters): EventCount => array_any(
                $filters->toArray(),
                static fn (Filter $filter): bool => null !== $filter->getKinds() && $filter->getKinds()->contains(EventKind::fromInt(EventKind::GIFT_WRAP)),
            ) ? $giftWraps : $total,
        );

        return new ReadableEventCounter($eventStore, new GiftWrapFilterRules());
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function filters(array $filter): FilterCollection
    {
        return new FilterCollection([Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse')]);
    }
}
