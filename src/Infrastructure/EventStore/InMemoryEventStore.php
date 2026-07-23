<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\EventStore;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Override;

/**
 * A non-persistent event store held in process memory, the counterpart of the in-memory
 * authentication, subscription and client registries. It is enough to run and exercise a relay
 * locally and to give a test a real store instead of a hand-rolled double; everything it holds is
 * lost when the process exits, so a deployment supplies a durable `RelayEventStoreInterface`.
 *
 * Matching is linear over the retained events, which is fine for a development relay and is the
 * reason this is not offered as a production adapter.
 */
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

            foreach ($filters as $filter) {
                if ($filter->matches($event)) {
                    $matched[] = $event;

                    break;
                }
            }
        }

        return new EventCollection($matched);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters): int
    {
        return $this->findByFilters($filters, PHP_INT_MAX)->count();
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
