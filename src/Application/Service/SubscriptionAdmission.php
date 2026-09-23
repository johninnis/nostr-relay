<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class SubscriptionAdmission
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimitGate $rateLimitGate,
        private SubscriptionLookupInterface $subscriptionLookup,
    ) {
    }

    public function admit(RelayClient $client, FilterCollection $filters): PolicyRejection|ScopedFilters
    {
        $rateLimit = $this->rateLimitGate->admit($client);

        if (null !== $rateLimit) {
            return $rateLimit;
        }

        return $this->scopeFor($client, $filters, $this->heldCount($client));
    }

    // Deliberate: no rate-limit token, and the client's own subscription discounted from the cap, because a re-evaluation replaces one it already holds rather than asking for another — see ADR-0016
    public function readmit(RelayClient $client, FilterCollection $filters): PolicyRejection|ScopedFilters
    {
        return $this->scopeFor($client, $filters, max(0, $this->heldCount($client) - 1));
    }

    private function scopeFor(RelayClient $client, FilterCollection $filters, int $heldCount): PolicyRejection|ScopedFilters
    {
        return $this->policy->allowSubscription($client, $filters, $heldCount)
            ?? $this->policy->filterForClient($client, $filters);
    }

    private function heldCount(RelayClient $client): int
    {
        return $this->subscriptionLookup->getSubscriptionCountForClient($client->getId());
    }
}
