<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;

final readonly class StoredEventReadGate
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private DeliveryGate $deliveryGate,
        private ClockInterface $clock,
    ) {
    }

    // Deliberate: one store read for the whole request, confined by the reader's acting keys in the store, then put in NIP-01's order so the wire never depends on the store's — see ADR-0039 and ADR-0036
    public function readableFor(RelayClient $client, AdmittedRead $read): StoredEventCollection
    {
        $now = $this->clock->now();

        // Deliberate: an event that expired while stored is withheld, not purged — see ADR-0014
        return new StoredEventCollection(array_values(array_filter(
            $this->eventStore->findByFilters($read->getFilters(), $read->getReader())->newestFirst()->toArray(),
            fn (StoredEvent $event): bool => !$event->isExpiredAt($now) && $this->deliveryGate->allowsStored($client, $event->getHeader()),
        )));
    }
}
