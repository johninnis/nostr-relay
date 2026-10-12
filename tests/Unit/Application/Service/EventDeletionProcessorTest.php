<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Tests\Support\ActingKeysMother;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class EventDeletionProcessorTest extends TestCase
{
    public function testTheReferencedIdsAreHandedToTheStore(): void
    {
        $target = $this->event(self::author(), EventKind::TEXT_NOTE);

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('deleteByEventIds')
            ->with($this->callback(static fn (EventIdCollection $ids): bool => [$target->getId()->toHex()] === $ids->toHexes()), $this->anything())
            ->willReturn(1);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->deletionOf($target));
    }

    public function testTheStoreDeletesOnBehalfOfTheDeletionsSignerAlone(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('deleteByEventIds')
            ->with($this->anything(), $this->callback(static fn (ActingKeys $deleter): bool => [self::author()->toHex()] === $deleter->getKeys()->toHexes()))
            ->willReturn(0);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->deletionOf($this->event(self::stranger(), EventKind::TEXT_NOTE)));
    }

    public function testADeletionReadsNothingBackFromTheStore(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('findByFilters');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->deletionOf($this->event(self::author(), EventKind::TEXT_NOTE)));
    }

    public function testEveryReferencedIdIsHandedToTheStoreInOneDeleteWhateverTheirNumber(): void
    {
        $tags = array_map(
            static fn (int $i): Tag => Tag::event(EventId::tryFromHex(str_pad(dechex($i), 64, '0', STR_PAD_LEFT)) ?? throw new RuntimeException('Invalid id')),
            range(1, 1001),
        );

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('deleteByEventIds')
            ->with($this->callback(static fn (EventIdCollection $ids): bool => 1001 === $ids->count()), $this->anything())
            ->willReturn(0);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->event(self::author(), EventKind::EVENT_DELETION, new TagCollection($tags)));
    }

    public function testADeletionRemovesItsAuthorsEvent(): void
    {
        $target = $this->event(self::author(), EventKind::TEXT_NOTE);

        $this->assertSame([], $this->remainingAfter($this->deletionOf($target), $target));
    }

    public function testADeletionLeavesAnotherAuthorsEvent(): void
    {
        $target = $this->event(self::stranger(), EventKind::TEXT_NOTE);

        $this->assertSame([$target->getId()->toHex()], $this->remainingAfter($this->deletionOf($target), $target));
    }

    public function testADeletionRemovesAGiftWrapAddressedToItsAuthor(): void
    {
        $giftWrap = EventMother::giftWrapFor(self::author());

        $this->assertSame([], $this->remainingAfter($this->deletionOf($giftWrap), $giftWrap));
    }

    public function testADeletionLeavesAGiftWrapAddressedToAnotherKey(): void
    {
        $giftWrap = EventMother::giftWrapFor(self::stranger());

        $this->assertSame([$giftWrap->getId()->toHex()], $this->remainingAfter($this->deletionOf($giftWrap), $giftWrap));
    }

    public function testADeletionRequestAgainstADeletionRequestHasNoEffect(): void
    {
        $earlierDeletion = $this->deletionOf($this->event(self::author(), EventKind::TEXT_NOTE));

        $this->assertSame([$earlierDeletion->getId()->toHex()], $this->remainingAfter($this->deletionOf($earlierDeletion), $earlierDeletion));
    }

    public function testAnEventReferenceDeletesNoCoordinates(): void
    {
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->deletionOf($this->event(self::stranger(), EventKind::TEXT_NOTE)));
    }

    public function testAReplaceableCoordinateIsDeletedUpToTheRequestsCreatedAt(): void
    {
        $coordinate = EventCoordinate::tryFrom(EventKind::fromInt(EventKind::METADATA), self::author(), '') ?? throw new RuntimeException('Invalid coordinate');
        $deletion = $this->event(self::author(), EventKind::EVENT_DELETION, new TagCollection([$coordinate->toATag()]), Timestamp::fromInt(1_700_000_000));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('deleteByCoordinates')
            ->with(
                $this->callback(static fn (EventCoordinateCollection $coordinates): bool => 1 === $coordinates->count() && $coordinates->toArray()[0]->equals($coordinate)),
                $this->callback(static fn (PublicKey $pubkey): bool => $pubkey->equals(self::author())),
                $this->callback(static fn (Timestamp $until): bool => 1_700_000_000 === $until->toInt()),
            )
            ->willReturn(1);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    public function testACoordinateOfAnotherAuthorDeletesNothing(): void
    {
        $coordinate = EventCoordinate::tryFrom(EventKind::fromInt(EventKind::METADATA), self::stranger(), '') ?? throw new RuntimeException('Invalid coordinate');

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->event(self::author(), EventKind::EVENT_DELETION, new TagCollection([$coordinate->toATag()])));
    }

    public function testACoordinateInAQTagDeletesNothing(): void
    {
        $coordinate = EventCoordinate::tryFrom(EventKind::fromInt(EventKind::METADATA), self::author(), '') ?? throw new RuntimeException('Invalid coordinate');

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($this->event(self::author(), EventKind::EVENT_DELETION, new TagCollection([Tag::fromArray(['q', (string) $coordinate])])));
    }

    /**
     * @return list<string>
     */
    private function remainingAfter(Event $deletion, Event $target): array
    {
        $eventStore = new InMemoryEventStore();
        $eventStore->store($target);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);

        return array_map(
            static fn (StoredEvent $event): string => $event->getHeader()->getId()->toHex(),
            $eventStore->findByFilters(new FilterCollection([Filter::from(ids: new EventIdCollection([$target->getId()]))]), ActingKeysMother::of(self::author(), self::stranger()))->toArray(),
        );
    }

    private function deletionOf(Event $target): Event
    {
        return $this->event(self::author(), EventKind::EVENT_DELETION, new TagCollection([Tag::event($target->getId())]));
    }

    private function event(PublicKey $author, int $kind, ?TagCollection $tags = null, ?Timestamp $createdAt = null): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            $author,
            EventKind::fromInt($kind),
            EventContent::fromString('x'),
            $tags ?? new TagCollection(),
            $createdAt,
        ));
    }

    private static function author(): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
    }

    private static function stranger(): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat('bb', 32)) ?? throw new RuntimeException('Invalid pubkey');
    }
}
