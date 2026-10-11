<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;

final readonly class DeliveryGate
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private GiftWrapFilterRules $giftWrapRules,
    ) {
    }

    // Deliberate: judged from the event's header and the filters of the one group that read it, never the event's tags, so stored bytes are delivered without a parse — see ADR-0022 and ADR-0035
    public function allowsStored(RelayClient $client, FilterCollection $group, EventHeader $header): bool
    {
        return !$this->giftWrapRules->withholds($group, $header) && $this->policy->canClientReceiveEvent($client, $header);
    }

    public function allowsLive(RelayClient $client, FilterCollection $filters, Event $event): bool
    {
        return $this->giftWrapRules->matches($filters, $event) && $this->policy->canClientReceiveEvent($client, EventHeader::of($event));
    }
}
