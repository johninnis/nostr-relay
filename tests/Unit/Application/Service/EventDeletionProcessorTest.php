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
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class EventDeletionProcessorTest extends TestCase
{
    public function testDeletesEventsOwnedByTheAuthor(): void
    {
        $author = PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $target = $this->event($author, EventKind::fromInt(EventKind::TEXT_NOTE));
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::event($target->getId())]));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($target)]));
        $eventStore->expects($this->once())
            ->method('deleteByEventIds')
            ->with(
                $this->callback(static fn (EventIdCollection $ids): bool => 1 === $ids->count() && $target->getId()->toHex() === $ids->toArray()[0]->toHex()),
                $this->callback(static fn (PublicKey $pubkey): bool => $pubkey->equals($author)),
            )
            ->willReturn(1);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    public function testSkipsEventsOwnedBySomeoneElse(): void
    {
        $author = PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $stranger = PublicKey::tryFromHex(str_repeat('bb', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $strangerEvent = $this->event($stranger, EventKind::fromInt(EventKind::TEXT_NOTE));
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::event($strangerEvent->getId())]));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($strangerEvent)]));
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    public function testADeletionRequestAgainstADeletionRequestHasNoEffect(): void
    {
        $author = PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $earlierDeletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::event($this->event($author, EventKind::fromInt(EventKind::TEXT_NOTE))->getId())]));
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::event($earlierDeletion->getId())]));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn(new StoredEventCollection([StoredEvent::of($earlierDeletion)]));
        $eventStore->expects($this->never())->method('deleteByEventIds');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    public function testAReplaceableCoordinateIsDeletedUpToTheRequestsCreatedAt(): void
    {
        $author = PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $coordinate = EventCoordinate::tryFrom(EventKind::fromInt(EventKind::METADATA), $author, '') ?? throw new RuntimeException('Invalid coordinate');
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([$coordinate->toATag()]), Timestamp::fromInt(1_700_000_000));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('deleteByCoordinates')
            ->with(
                $this->callback(static fn (EventCoordinateCollection $coordinates): bool => 1 === $coordinates->count() && $coordinates->toArray()[0]->equals($coordinate)),
                $this->callback(static fn (PublicKey $pubkey): bool => $pubkey->equals($author)),
                $this->callback(static fn (Timestamp $until): bool => 1_700_000_000 === $until->toInt()),
            )
            ->willReturn(1);

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    public function testACoordinateInAQTagDeletesNothing(): void
    {
        $author = PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $coordinate = EventCoordinate::tryFrom(EventKind::fromInt(EventKind::METADATA), $author, '') ?? throw new RuntimeException('Invalid coordinate');
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::fromArray(['q', (string) $coordinate])]));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    public function testLooksUpEveryReferencedIdInOneFilterWhateverTheirNumber(): void
    {
        $author = PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $tags = array_map(
            static fn (int $i): Tag => Tag::event(EventId::tryFromHex(str_pad(dechex($i), 64, '0', STR_PAD_LEFT)) ?? throw new RuntimeException('Invalid id')),
            range(1, 1001),
        );
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection($tags));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('findByFilters')
            ->with($this->callback(static fn (FilterCollection $filters): bool => 1 === $filters->count() && 1001 === $filters->toArray()[0]->getIds()?->count()))
            ->willReturn(new StoredEventCollection([]));

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    private function event(PublicKey $author, EventKind $kind, ?TagCollection $tags = null, ?Timestamp $createdAt = null): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            $author,
            $kind,
            EventContent::fromString('x'),
            $tags ?? new TagCollection(),
            $createdAt,
        ));
    }
}
