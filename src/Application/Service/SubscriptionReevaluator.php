<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

final readonly class SubscriptionReevaluator
{
    public function __construct(
        private SubscriptionManager $subscriptionManager,
        private CreateSubscriptionUseCase $createSubscription,
    ) {
    }

    public function reevaluate(RelayClient $client): void
    {
        foreach ($this->subscriptionManager->getSubscriptionsForClient($client->getId()) as $subscription) {
            $originalFilters = $this->subscriptionManager->getOriginalFilters($client->getId(), $subscription->getId());

            if ($originalFilters->isEmpty()) {
                continue;
            }

            $this->createSubscription->execute($client, $subscription->getId(), $originalFilters);
        }
    }
}
