<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\EventKindCategory;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;

final readonly class AcceptedEventPipeline
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private AcceptedEventPublisher $publisher,
        private EventDeletionProcessor $deletionProcessor,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function accept(RelayClient $client, Event $event): array
    {
        if (EventKindCategory::Ephemeral === $event->getKind()->category()) {
            $this->publisher->publish($client, $event);

            return [OkMessage::accepted($event->getId())];
        }

        return match ($this->eventStore->store($event)) {
            EventStoreOutcome::Stored => $this->onStored($client, $event),
            EventStoreOutcome::Duplicate => [OkMessage::refused($event->getId(), ReasonPrefix::Duplicate, 'event already exists')],
            EventStoreOutcome::Superseded => [OkMessage::refused($event->getId(), ReasonPrefix::Duplicate, 'newer version already exists')],
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

        return [OkMessage::accepted($event->getId())];
    }
}
