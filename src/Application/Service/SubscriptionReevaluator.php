<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

final readonly class SubscriptionReevaluator
{
    public function __construct(
        private SubscriptionLookupInterface $subscriptionLookup,
        private SubscriptionActivator $activator,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function reevaluate(RelayClient $client): array
    {
        $replies = [];

        foreach ($this->subscriptionLookup->getSubscriptionsForClient($client->getId()) as $subscription) {
            $originalFilters = $this->subscriptionLookup->getOriginalFilters($client->getId(), $subscription->getId());

            if ($originalFilters->isEmpty()) {
                continue;
            }

            $replies = [...$replies, ...$this->activator->activate($client, $subscription->getId(), $originalFilters)];
        }

        return $replies;
    }
}
