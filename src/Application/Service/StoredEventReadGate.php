<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;

final readonly class StoredEventReadGate
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private DeliveryGate $deliveryGate,
        private ClockInterface $clock,
    ) {
    }

    // Deliberate: each group is read on its own, so a gift wrap is judged by the group that read it and a split filter answers within its one limit, and each group is put in NIP-01's order before it is cut to that limit and merged — see ADR-0035
    public function readableFor(RelayClient $client, ScopedFilters $scoped): StoredEventCollection
    {
        $now = $this->clock->now();
        $readable = [];

        foreach ($scoped->getGroups() as $group) {
            foreach ($this->readableInGroup($client, $group, $now) as $event) {
                $readable[$event->getHeader()->getId()->toHex()] ??= $event;
            }
        }

        return new StoredEventCollection($readable)->newestFirst();
    }

    // Deliberate: an event that expired while stored is withheld, not purged — see ADR-0014
    /**
     * @return list<StoredEvent>
     */
    private function readableInGroup(RelayClient $client, FilterCollection $group, Timestamp $now): array
    {
        $limit = self::limitOf($group);
        $events = $this->eventStore->findByFilters($group)->newestFirst()->toArray();

        return array_values(array_filter(
            null === $limit ? $events : array_slice($events, 0, $limit),
            fn (StoredEvent $event): bool => !$event->isExpiredAt($now) && $this->deliveryGate->allowsStored($client, $group, $event->getHeader()),
        ));
    }

    private static function limitOf(FilterCollection $group): ?int
    {
        $limits = array_map(static fn (Filter $filter): ?int => $filter->getLimit(), $group->toArray());

        return [] === $limits || in_array(null, $limits, true) ? null : max($limits);
    }
}
