<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Collection\SubscriptionMatchCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface SubscriptionLookupInterface
{
    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection;

    public function getSubscriptionCountForClient(ClientId $clientId): int;

    public function getSubscriptionsForEvent(EventKind $kind): SubscriptionMatchCollection;

    public function getOriginalFilters(ClientId $clientId, SubscriptionId $subscriptionId): FilterCollection;
}
