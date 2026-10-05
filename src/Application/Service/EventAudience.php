<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Collection\EventRecipientCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\EventRecipient;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;

final readonly class EventAudience
{
    public function __construct(
        private RelayPolicyInterface $policy,
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

        $header = EventHeader::of($event);

        return new EventRecipientCollection(array_values(array_filter(array_map(
            fn (SubscriptionMatch $match): ?EventRecipient => $this->recipientOf($match, $event, $header),
            $matches->toArray(),
        ))));
    }

    private function recipientOf(SubscriptionMatch $match, Event $event, EventHeader $header): ?EventRecipient
    {
        if (!$match->getSubscription()->matchesEvent($event)) {
            return null;
        }

        $client = $this->clientRegistry->getClient($match->getClientId());

        return $client instanceof RelayClient && $this->policy->canClientReceiveEvent($client, $header)
            ? new EventRecipient($client, $match->getSubscription()->getId())
            : null;
    }
}
