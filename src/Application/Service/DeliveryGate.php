<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;

final readonly class DeliveryGate
{
    public function __construct(
        private RelayPolicyInterface $policy,
    ) {
    }

    // Deliberate: judged from the event's header, never its tags, so stored bytes are delivered without a parse; the store has already confined gift wraps to the reader — see ADR-0022 and ADR-0036
    public function allowsStored(RelayClient $client, EventHeader $header): bool
    {
        return $this->policy->canClientReceiveEvent($client, $header);
    }

    // Deliberate: a live event is judged by the same reader's acting keys the store applies, the one its subscription was admitted under — see ADR-0033 and ADR-0036
    public function allowsLive(RelayClient $client, SubscriptionMatch $match, Event $event): bool
    {
        return $match->getSubscription()->getFilters()->matches($event)
            && $match->getReader()->canRead($event)
            && $this->policy->canClientReceiveEvent($client, EventHeader::of($event));
    }
}
