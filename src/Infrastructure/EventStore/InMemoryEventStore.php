<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\EventStore;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventVersion;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Override;

final class InMemoryEventStore implements RelayEventStoreInterface
{
    /** @var array<string, Event> */
    private array $events = [];

    /** @var array<string, StoredEvent> */
    private array $encoded = [];

    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        $id = $event->getId()->toHex();

        if (isset($this->events[$id])) {
            return EventStoreOutcome::Duplicate;
        }

        $incumbent = $this->incumbentFor($event);

        if ($incumbent instanceof Event) {
            if (!EventVersion::of($event)->supersedes(EventVersion::of($incumbent))) {
                return EventStoreOutcome::Superseded;
            }

            $this->remove($incumbent->getId()->toHex());
        }

        $this->events[$id] = $event;
        $this->encoded[$id] = StoredEvent::of($event);

        return EventStoreOutcome::Stored;
    }

    private function incumbentFor(Event $event): ?Event
    {
        $coordinate = EventCoordinate::tryFromEvent($event);

        return null === $coordinate ? null : array_find(
            $this->events,
            static fn (Event $stored): bool => $coordinate->matchesEvent($stored),
        );
    }

    #[Override]
    public function findByFilters(FilterCollection $filters): StoredEventCollection
    {
        $matched = [];

        foreach ($filters as $filter) {
            foreach ($this->newestMatching($filter) as $event) {
                $matched[$event->getId()->toHex()] = $event;
            }
        }

        return new StoredEventCollection(array_map(
            fn (Event $event): StoredEvent => $this->encoded[$event->getId()->toHex()],
            new EventCollection(array_values($matched))->sortByTimestamp(false)->toArray(),
        ));
    }

    private function newestMatching(Filter $filter): EventCollection
    {
        $matching = new EventCollection(array_values(array_filter(
            $this->events,
            static fn (Event $event): bool => $filter->matches($event),
        )))->sortByTimestamp(false);

        $limit = $filter->getLimit();

        return null === $limit ? $matching : $matching->slice(0, $limit);
    }

    // Deliberate: counts matches directly rather than reusing the read path, because a filter's limit bounds the reply to a REQ and not the number of events that match — see ADR-0013
    #[Override]
    public function countByFilters(FilterCollection $filters): EventCount
    {
        $matched = [];

        foreach ($filters as $filter) {
            foreach ($this->events as $id => $event) {
                if ($filter->matches($event)) {
                    $matched[$id] = true;
                }
            }
        }

        return EventCount::exact(count($matched));
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        $deleted = 0;

        foreach ($eventIds as $eventId) {
            $id = $eventId->toHex();

            if (isset($this->events[$id]) && $this->events[$id]->getPubkey()->equals($author)) {
                $this->remove($id);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author, Timestamp $until): int
    {
        $deleted = 0;

        foreach ($this->events as $id => $event) {
            if (!$event->getPubkey()->equals($author) || $event->getCreatedAt()->isAfter($until)) {
                continue;
            }

            foreach ($coordinates as $coordinate) {
                if ($coordinate->matchesEvent($event)) {
                    $this->remove($id);
                    ++$deleted;

                    break;
                }
            }
        }

        return $deleted;
    }

    private function remove(string $id): void
    {
        unset($this->events[$id], $this->encoded[$id]);
    }
}
