<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\EventStore;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\EventKindCategory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventVersion;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
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

        $incumbent = $this->incumbentFor($event);

        if ($incumbent instanceof Event) {
            if (!EventVersion::of($event)->supersedes(EventVersion::of($incumbent))) {
                return EventStoreOutcome::Superseded;
            }

            unset($this->events[$incumbent->getId()->toHex()]);
        }

        $this->events[$id] = $event;

        return EventStoreOutcome::Stored;
    }

    private function incumbentFor(Event $event): ?Event
    {
        $category = $event->getKind()->category();

        if (EventKindCategory::Replaceable !== $category && EventKindCategory::Addressable !== $category) {
            return null;
        }

        $identifier = EventKindCategory::Addressable === $category ? self::identifierOf($event) : null;

        return array_find(
            $this->events,
            static fn (Event $stored): bool => $stored->getKind()->equals($event->getKind())
                && $stored->getPubkey()->equals($event->getPubkey())
                && (null === $identifier || self::identifierOf($stored) === $identifier),
        );
    }

    private static function identifierOf(Event $event): string
    {
        return $event->getTags()->getFirstValueByType(TagType::identifier()) ?? '';
    }

    #[Override]
    public function findByFilters(FilterCollection $filters): EventCollection
    {
        $matched = [];

        foreach ($filters as $filter) {
            foreach ($this->newestMatching($filter) as $event) {
                $matched[$event->getId()->toHex()] = $event;
            }
        }

        return new EventCollection(array_values($matched))->sortByTimestamp(false);
    }

    private function newestMatching(Filter $filter): EventCollection
    {
        $matching = new EventCollection(array_values(array_filter(
            $this->events,
            static fn (Event $event): bool => $filter->matches($event),
        )))->sortByTimestamp(false);

        return $filter->hasLimit() ? $matching->slice(0, $filter->getLimit() ?? 0) : $matching;
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
                unset($this->events[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
    {
        $deleted = 0;

        foreach ($this->events as $id => $event) {
            if (!$event->getPubkey()->equals($author)) {
                continue;
            }

            foreach ($coordinates as $coordinate) {
                if ($coordinate->matchesEvent($event)) {
                    unset($this->events[$id]);
                    ++$deleted;

                    break;
                }
            }
        }

        return $deleted;
    }
}
