<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class SubscriptionAdmission
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimiterInterface $rateLimiter,
        private SubscriptionLookupInterface $subscriptionLookup,
    ) {
    }

    public function admit(RelayClient $client, FilterCollection $filters): PolicyRejection|ScopedFilters
    {
        if (!$this->policy->isRateLimitExempt($client)
            && !$this->rateLimiter->tryConsume($client->getConnectionInfo()->getIpAddress())) {
            return PolicyRejection::rateLimited('slow down');
        }

        $rejection = $this->policy->allowSubscription(
            $client,
            $filters,
            $this->subscriptionLookup->getSubscriptionCountForClient($client->getId()),
        );
        if (null !== $rejection) {
            return $rejection;
        }

        return $this->policy->filterForClient($client, $filters);
    }
}
