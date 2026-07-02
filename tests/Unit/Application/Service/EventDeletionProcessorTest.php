<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class EventDeletionProcessorTest extends TestCase
{
    public function testDeletesEventsOwnedByTheAuthor(): void
    {
        $author = PublicKey::fromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $target = $this->event($author, EventKind::fromInt(EventKind::TEXT_NOTE));
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::event($target->getId())]));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn(new EventCollection([$target]));
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
        $author = PublicKey::fromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $stranger = PublicKey::fromHex(str_repeat('bb', 32)) ?? throw new RuntimeException('Invalid pubkey');
        $strangerEvent = $this->event($stranger, EventKind::fromInt(EventKind::TEXT_NOTE));
        $deletion = $this->event($author, EventKind::fromInt(EventKind::EVENT_DELETION), new TagCollection([Tag::event($strangerEvent->getId())]));

        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn(new EventCollection([$strangerEvent]));
        $eventStore->expects($this->never())->method('deleteByEventIds');
        $eventStore->expects($this->never())->method('deleteByCoordinates');

        new EventDeletionProcessor($eventStore, new NullLogger())->process($deletion);
    }

    private function event(PublicKey $author, EventKind $kind, ?TagCollection $tags = null): Event
    {
        return EventMother::fromRumour(new Rumour(
            $author,
            Timestamp::now(),
            $kind,
            $tags ?? new TagCollection(),
            EventContent::fromString('x'),
        ));
    }
}
