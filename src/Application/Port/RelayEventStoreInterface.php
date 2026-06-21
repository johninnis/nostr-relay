<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\EventCollection;
use Innis\Nostr\Core\Domain\Entity\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

interface RelayEventStoreInterface
{
    public function store(Event $event): EventStoreOutcome;

    public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection;

    public function countByFilters(FilterCollection $filters): int;

    public function deleteByEventIds(array $eventIds, PublicKey $author): int;

    public function deleteByCoordinates(array $coordinates, PublicKey $author): int;
}
