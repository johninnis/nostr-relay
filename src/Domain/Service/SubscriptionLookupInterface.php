<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\SubscriptionCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface SubscriptionLookupInterface
{
    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection;

    public function getSubscriptionCountForClient(ClientId $clientId): int;
}
