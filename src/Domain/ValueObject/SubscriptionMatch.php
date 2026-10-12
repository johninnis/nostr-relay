<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Entity\Subscription;

final readonly class SubscriptionMatch
{
    public function __construct(
        private ClientId $clientId,
        private Subscription $subscription,
        private ActingKeys $reader,
    ) {
    }

    public function getClientId(): ClientId
    {
        return $this->clientId;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getReader(): ActingKeys
    {
        return $this->reader;
    }
}
