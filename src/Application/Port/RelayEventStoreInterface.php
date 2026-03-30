<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;

interface RelayEventStoreInterface
{
    public function store(Event $event): bool;

    public function findByFilters(array $filters, int $limit = 100): array;

    public function countByFilters(array $filters): int;
}
