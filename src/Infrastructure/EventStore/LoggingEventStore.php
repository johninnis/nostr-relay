<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\EventStore;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Override;
use Psr\Log\LoggerInterface;

final readonly class LoggingEventStore implements RelayEventStoreInterface
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        $outcome = $this->eventStore->store($event);

        $this->logger->debug('Event store answered '.$outcome->value, [
            'event_id' => $event->getId()->toHex(),
            'pubkey' => $event->getPubkey()->toHex(),
            'kind' => $event->getKind()->toInt(),
        ]);

        return $outcome;
    }

    #[Override]
    public function findByFilters(FilterCollection $filters): StoredEventCollection
    {
        return $this->eventStore->findByFilters($filters);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters): EventCount
    {
        return $this->eventStore->countByFilters($filters);
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        return $this->eventStore->deleteByEventIds($eventIds, $author);
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author, Timestamp $until): int
    {
        return $this->eventStore->deleteByCoordinates($coordinates, $author, $until);
    }
}
