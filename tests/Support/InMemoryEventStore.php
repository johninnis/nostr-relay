<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Override;

final class InMemoryEventStore implements RelayEventStoreInterface
{
    /** @var array<string, Event> */
    private array $events = [];

    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        $id = $event->getId()->toHex();

        if (isset($this->events[$id])) {
            return EventStoreOutcome::Duplicate;
        }

        $this->events[$id] = $event;

        return EventStoreOutcome::Stored;
    }

    #[Override]
    public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection
    {
        $matched = [];

        foreach ($this->events as $event) {
            if (count($matched) >= $limit) {
                break;
            }

            if ($this->matchesAny($filters, $event)) {
                $matched[] = $event;
            }
        }

        return new EventCollection($matched);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters): int
    {
        return count(array_filter(
            $this->events,
            fn (Event $event): bool => $this->matchesAny($filters, $event),
        ));
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        $deleted = 0;

        foreach ($eventIds as $eventId) {
            $id = $eventId->toHex();

            if (isset($this->events[$id]) && $this->events[$id]->getPubkey()->equals($author)) {
                unset($this->events[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
    {
        return 0;
    }

    private function matchesAny(FilterCollection $filters, Event $event): bool
    {
        foreach ($filters as $filter) {
            if ($filter->matches($event)) {
                return true;
            }
        }

        return false;
    }
}
