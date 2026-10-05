<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventRecipient;
use Psr\Log\LoggerInterface;

final readonly class EventDistributor
{
    public function __construct(
        private EventAudience $audience,
        private ClientMessengerInterface $messenger,
        private LoggerInterface $logger,
    ) {
    }

    // Deliberate: no expiry check here — admission is the only way an event reaches this, and it refuses one that has already expired — see ADR-0014
    public function distributeToSubscribers(Event $event): void
    {
        $recipients = $this->audience->audienceFor($event);

        if ($recipients->isEmpty()) {
            return;
        }

        $encoded = EncodedEvent::of($event);
        $distributionCount = 0;

        foreach ($recipients as $recipient) {
            if ($this->send($recipient, $encoded)) {
                ++$distributionCount;
            }
        }

        if ($distributionCount > 0) {
            $this->logger->debug('Event distributed to subscriptions', [
                'event_id' => $event->getId()->toHex(),
                'subscription_count' => $distributionCount,
            ]);
        }
    }

    private function send(EventRecipient $recipient, EncodedEvent $encoded): bool
    {
        try {
            $this->messenger->sendEvent($recipient->getClient(), $recipient->getSubscriptionId(), $encoded);
        } catch (ConnectionException $e) {
            $this->logger->debug('Skipping send to disconnected subscriber', [
                'client_id' => (string) $recipient->getClient()->getId(),
                'subscription_id' => (string) $recipient->getSubscriptionId(),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
