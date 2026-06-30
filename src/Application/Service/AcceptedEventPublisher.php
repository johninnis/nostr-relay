<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Relay\Application\Port\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Port\ClientRegistryInterface;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

final readonly class AcceptedEventPublisher
{
    public function __construct(
        private ClientRegistryInterface $registry,
        private EventDistributor $distributor,
        private DeferredExecutorInterface $deferredExecutor,
        private ClientMessengerInterface $messenger,
    ) {
    }

    public function publish(RelayClient $client, Event $event): void
    {
        $this->registry->recordEventAccepted($client->getId());
        $this->deferredExecutor->defer(fn () => $this->distributor->distributeToSubscribers($event));
        $this->messenger->send($client, new OkMessage($event->getId(), true, ''));
    }
}
