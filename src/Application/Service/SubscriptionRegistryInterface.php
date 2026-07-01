<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface SubscriptionRegistryInterface extends SubscriptionLookupInterface
{
    public function addSubscription(ClientId $clientId, Subscription $subscription, ?FilterCollection $originalFilters = null): void;

    public function removeSubscription(ClientId $clientId, SubscriptionId $subscriptionId): void;

    public function removeAllForClient(ClientId $clientId): void;

    public function updateSubscriptionState(ClientId $clientId, SubscriptionId $subscriptionId, SubscriptionState $state): void;
}
