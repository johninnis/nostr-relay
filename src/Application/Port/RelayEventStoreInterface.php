<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

interface RelayEventStoreInterface
{
    public function store(Event $event): bool;

    public function findByFilters(array $filters, int $limit = 100): array;

    public function countByFilters(array $filters): int;

    public function deleteByEventIds(array $eventIds, PublicKey $author): int;

    public function deleteByCoordinates(array $coordinates, PublicKey $author): int;
}
