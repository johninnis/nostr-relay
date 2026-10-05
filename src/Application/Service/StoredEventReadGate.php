<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;

final readonly class StoredEventReadGate
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private RelayPolicyInterface $policy,
        private ClockInterface $clock,
    ) {
    }

    // Deliberate: an event that expired while stored is withheld, not purged — see ADR-0014
    public function readableFor(RelayClient $client, FilterCollection $filters): StoredEventCollection
    {
        $now = $this->clock->now();

        return new StoredEventCollection(array_values(array_filter(
            $this->eventStore->findByFilters($filters)->toArray(),
            fn (StoredEvent $event): bool => !$event->isExpiredAt($now) && $this->policy->canClientReceiveEvent($client, $event->getHeader()),
        )));
    }
}
