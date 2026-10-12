<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
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

        $requestedEventIds = $event->getTags()->getEventIds();
        $requestedCoordinates = $event->getTags()->getCoordinates();
        $requestedCount = count($requestedEventIds) + count($requestedCoordinates);

        $ownCoordinates = new EventCoordinateCollection(array_values(array_filter(
            $requestedCoordinates->toArray(),
            static fn (EventCoordinate $coordinate): bool => $coordinate->getPubkey()->equals($author),
        )));

        if (count($ownCoordinates) < count($requestedCoordinates)) {
            $this->logger->warning('Deletion event referenced events not owned by author', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'requested' => count($requestedCoordinates),
                'verified' => count($ownCoordinates),
            ]);
        }

        // Deliberate: the store judges each id itself, deleting what the signer signed and the gift wraps addressed to it, never a deletion, so no event is read back into the relay — see ADR-0038
        $deletedCount = ($requestedEventIds->isEmpty() ? 0 : $this->eventStore->deleteByEventIds($requestedEventIds, new ActingKeys(new PublicKeyCollection([$author]))))
            + ($ownCoordinates->isEmpty() ? 0 : $this->eventStore->deleteByCoordinates($ownCoordinates, $author, $event->getCreatedAt()));

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
}
