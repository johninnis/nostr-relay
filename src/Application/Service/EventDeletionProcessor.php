<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Psr\Log\LoggerInterface;

final readonly class EventDeletionProcessor
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private LoggerInterface $logger,
    ) {
    }

    public function process(Event $event): void
    {
        $author = $event->getPubkey();
        $deletedCount = 0;

        $requestedEventIds = $event->getTags()->getEventIds()->toArray();
        $requestedCoordinates = $event->getTags()->getCoordinates()->toArray();
        $requestedCount = count($requestedEventIds) + count($requestedCoordinates);

        $verifiedEventIds = $this->verifyOwnedEventIds($requestedEventIds, $author);
        $verifiedCoordinates = array_values(array_filter(
            $requestedCoordinates,
            static fn (EventCoordinate $coord) => $coord->getPubkey()->equals($author)
        ));
        $verifiedCount = count($verifiedEventIds) + count($verifiedCoordinates);

        if ($verifiedCount < $requestedCount) {
            $this->logger->warning('Deletion event referenced events not owned by author', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'requested' => $requestedCount,
                'verified' => $verifiedCount,
            ]);
        }

        if (!empty($verifiedEventIds)) {
            $deletedCount += $this->eventStore->deleteByEventIds(new EventIdCollection($verifiedEventIds), $author);
        }

        if (!empty($verifiedCoordinates)) {
            $deletedCount += $this->eventStore->deleteByCoordinates(new EventCoordinateCollection($verifiedCoordinates), $author, $event->getCreatedAt());
        }

        if ($deletedCount > 0) {
            $this->logger->debug('Deletion event processed', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'referenced' => $requestedCount,
                'deleted_count' => $deletedCount,
            ]);
        } elseif ($requestedCount > 0) {
            $this->logger->debug('Deletion event had no effect', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'referenced' => $requestedCount,
            ]);
        }
    }

    /**
     * @param list<EventId> $eventIds
     *
     * @return list<EventId>
     */
    private function verifyOwnedEventIds(array $eventIds, PublicKey $author): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $storedEvents = $this->eventStore->findByFilters(new FilterCollection([
            Filter::from(ids: new EventIdCollection($eventIds)),
        ]));

        $verified = [];
        foreach ($storedEvents as $storedEvent) {
            $header = $storedEvent->getHeader();

            if ($header->getPubkey()->equals($author) && !$header->getKind()->is(EventKind::EVENT_DELETION)) {
                $verified[] = $header->getId();
            }
        }

        return $verified;
    }
}
