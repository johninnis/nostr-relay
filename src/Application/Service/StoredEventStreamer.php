<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class StoredEventStreamer
{
    public function __construct(
        private StoredEventReadGate $readGate,
        private ClientMessengerInterface $messenger,
        private LoggerInterface $logger,
    ) {
    }

    public function stream(RelayClient $client, Subscription $subscription, FilterCollection $filters): void
    {
        try {
            $events = $this->readGate->readableFor($client, $filters);

            foreach ($events as $event) {
                $this->messenger->sendEvent($client, $subscription->getId(), $event->getEncoded());
            }

            $this->messenger->send($client, new EoseMessage($subscription->getId()));

            $this->logger->debug('Stored events sent, subscription now live', [
                'subscription_id' => (string) $subscription->getId(),
                'event_count' => count($events),
            ]);
        } catch (ConnectionException $e) {
            $this->logger->debug('Subscriber disconnected before stored events finished streaming', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscription->getId(),
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch stored events', [
                'subscription_id' => (string) $subscription->getId(),
                'error' => $e->getMessage(),
            ]);

            try {
                $this->messenger->send($client, NoticeMessage::fromString(ReasonPrefix::Error->format('failed to fetch events')));
            } catch (ConnectionException) {
            }
        }
    }
}
