<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

interface RelayEventStoreInterface
{
    public function store(Event $event): EventStoreOutcome;

    public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection;

    public function countByFilters(FilterCollection $filters): int;

    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int;

    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int;
}
