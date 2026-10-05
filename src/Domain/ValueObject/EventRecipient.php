<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

final readonly class EventRecipient
{
    public function __construct(
        private RelayClient $client,
        private SubscriptionId $subscriptionId,
    ) {
    }

    public function getClient(): RelayClient
    {
        return $this->client;
    }

    public function getSubscriptionId(): SubscriptionId
    {
        return $this->subscriptionId;
    }
}
