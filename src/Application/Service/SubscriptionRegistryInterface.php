<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface SubscriptionRegistryInterface extends SubscriptionLookupInterface
{
    // Deliberate: a subscription keeps the reader's acting keys it was admitted under, so its live events are confined as its stored ones were — see ADR-0033 and ADR-0036
    public function addSubscription(ClientId $clientId, Subscription $subscription, ActingKeys $reader): void;

    public function removeSubscription(ClientId $clientId, SubscriptionId $subscriptionId): void;

    public function removeAllForClient(ClientId $clientId): void;

    public function updateSubscriptionState(ClientId $clientId, SubscriptionId $subscriptionId, SubscriptionState $state): void;
}
