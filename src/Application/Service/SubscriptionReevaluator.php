<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

final readonly class SubscriptionReevaluator
{
    public function __construct(
        private SubscriptionLookupInterface $subscriptionLookup,
        private CreateSubscriptionUseCase $createSubscription,
        private ClientMessengerInterface $messenger,
    ) {
    }

    public function reevaluate(RelayClient $client): void
    {
        foreach ($this->subscriptionLookup->getSubscriptionsForClient($client->getId()) as $subscription) {
            $originalFilters = $this->subscriptionLookup->getOriginalFilters($client->getId(), $subscription->getId());

            if ($originalFilters->isEmpty()) {
                continue;
            }

            foreach ($this->createSubscription->execute($client, $subscription->getId(), $originalFilters) as $reply) {
                $this->messenger->send($client, $reply);
            }
        }
    }
}
