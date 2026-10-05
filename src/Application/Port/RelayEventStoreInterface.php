<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

interface RelayEventStoreInterface
{
    // Deliberate: a store is handed an Event, never bytes, and keeps EncodedEvent::of() of it, so what findByFilters returns may be streamed unparsed — see ADR-0021
    public function store(Event $event): EventStoreOutcome;

    // Deliberate: a store returns the union of what the filters match, newest first, applying each filter's own limit — the relay bounds a read by bounding the filters, never by a second number here — see ADR-0024
    public function findByFilters(FilterCollection $filters): StoredEventCollection;

    // Deliberate: a filter's limit bounds a REQ reply and says nothing about how many events match, so a count ignores it — a store that stops early stops at its own ceiling and says the count is approximate — see ADR-0013
    public function countByFilters(FilterCollection $filters): EventCount;

    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int;

    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author, Timestamp $until): int;
}
