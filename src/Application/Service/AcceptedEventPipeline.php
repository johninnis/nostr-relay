<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\EventKindCategory;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\Enum\RejectionReason;
use Psr\Log\LoggerInterface;

final readonly class AcceptedEventPipeline
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private AcceptedEventPublisher $publisher,
        private EventDeletionProcessor $deletionProcessor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function accept(RelayClient $client, Event $event): array
    {
        if (EventKindCategory::Ephemeral === $event->getKind()->category()) {
            $this->publisher->publish($client, $event);
            $this->logger->debug('Event accepted (ephemeral)', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex()]);

            return [new OkMessage($event->getId(), true, '')];
        }

        return match ($this->eventStore->store($event)) {
            EventStoreOutcome::Stored => $this->onStored($client, $event),
            EventStoreOutcome::Duplicate => $this->onDuplicate($event),
            EventStoreOutcome::Superseded => $this->onSuperseded($event),
        };
    }

    /**
     * @return list<RelayMessage>
     */
    private function onStored(RelayClient $client, Event $event): array
    {
        if ($event->isDeletion()) {
            $this->deletionProcessor->process($event);
        }

        $this->publisher->publish($client, $event);

        $this->logger->debug('Event stored', [
            'event_id' => $event->getId()->toHex(),
            'pubkey' => $event->getPubkey()->toHex(),
            'kind' => $event->getKind()->toInt(),
        ]);

        return [new OkMessage($event->getId(), true, '')];
    }

    /**
     * @return list<RelayMessage>
     */
    private function onDuplicate(Event $event): array
    {
        $this->logger->debug('Event duplicate', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex()]);

        return [new OkMessage($event->getId(), false, RejectionReason::Duplicate->format('event already exists'))];
    }

    /**
     * @return list<RelayMessage>
     */
    private function onSuperseded(Event $event): array
    {
        $this->logger->debug('Event superseded', [
            'event_id' => $event->getId()->toHex(),
            'pubkey' => $event->getPubkey()->toHex(),
            'kind' => $event->getKind()->toInt(),
        ]);

        return [new OkMessage($event->getId(), false, RejectionReason::Duplicate->format('newer version already exists'))];
    }
}
