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
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;

interface RelayEventStoreInterface
{
    // Deliberate: a store is handed an Event, never bytes, and keeps EncodedEvent::of() of it, so what findByFilters returns may be streamed unparsed — see ADR-0039
    public function store(Event $event): EventStoreOutcome;

    // Deliberate: a store returns the union, each event once, newest first, the lower id first at a tie, applying each filter's own limit, and on every filter, before that limit, matches a gift-wrap kind only where a p tag names one of the reader's keys; no read is unconfined — see ADR-0024 and ADR-0036
    public function findByFilters(FilterCollection $filters, ActingKeys $reader): StoredEventCollection;

    // Deliberate: a count is the size of the union the filters match, each event once as NIP-45 aggregates them, ignoring each filter's limit and confining gift wraps as a read does — a store that stops early stops at its own ceiling and says the count is approximate — see ADR-0013 and ADR-0036
    public function countByFilters(FilterCollection $filters, ActingKeys $reader): EventCount;

    // Deliberate: of these ids a store deletes what the deleter signed and the gift wraps addressed to it, never a deletion, in the delete itself — see ADR-0038
    public function deleteByEventIds(EventIdCollection $eventIds, ActingKeys $deleter): int;

    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author, Timestamp $until): int;
}
