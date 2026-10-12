<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Domain\Collection\EventRecipientCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EventRecipient;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;

final readonly class EventAudience
{
    public function __construct(
        private DeliveryGate $deliveryGate,
        private SubscriptionLookupInterface $subscriptionLookup,
        private ClientRegistryInterface $clientRegistry,
    ) {
    }

    public function audienceFor(Event $event): EventRecipientCollection
    {
        $matches = $this->subscriptionLookup->getSubscriptionsForEvent($event->getKind());

        if ($matches->isEmpty()) {
            return new EventRecipientCollection([]);
        }

        return new EventRecipientCollection(array_values(array_filter(array_map(
            fn (SubscriptionMatch $match): ?EventRecipient => $this->recipientOf($match, $event),
            $matches->toArray(),
        ))));
    }

    private function recipientOf(SubscriptionMatch $match, Event $event): ?EventRecipient
    {
        $client = $this->clientRegistry->getClient($match->getClientId());

        return $client instanceof RelayClient && $this->deliveryGate->allowsLive($client, $match, $event)
            ? new EventRecipient($client, $match->getSubscription()->getId())
            : null;
    }
}
