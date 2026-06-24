<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Service\TagReferenceExtractor;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Reference\EventReference;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Psr\Log\LoggerInterface;

final class EventDeletionProcessor
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Event $event): void
    {
        $references = TagReferenceExtractor::extract($event->getTags());
        $author = $event->getPubkey();
        $deletedCount = 0;

        $requestedEventIds = array_map(
            static fn (EventReference $ref) => $ref->getEventId(),
            $references->getEvents()->toArray()
        );
        $requestedCoordinates = $references->getAddressable()->toArray();
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
            $deletedCount += $this->eventStore->deleteByCoordinates(new EventCoordinateCollection($verifiedCoordinates), $author);
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

    private function verifyOwnedEventIds(array $eventIds, PublicKey $author): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $filters = array_map(
            static fn (array $chunk) => new Filter(ids: array_map(
                static fn (EventId $id) => $id->toHex(),
                $chunk
            )),
            array_chunk($eventIds, Filter::MAX_VALUES_PER_FIELD)
        );

        $storedEvents = $this->eventStore->findByFilters(new FilterCollection($filters), count($eventIds));

        $verified = [];
        foreach ($storedEvents as $storedEvent) {
            if ($storedEvent->getPubkey()->equals($author)) {
                $verified[] = $storedEvent->getId();
            }
        }

        return $verified;
    }
}
