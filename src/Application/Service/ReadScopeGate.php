<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class ReadScopeGate
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private AuthenticatedSessionsInterface $authenticatedSessions,
        private GiftWrapFilterRules $giftWrapRules,
    ) {
    }

    // Deliberate: gift wraps are confined after every policy, so no policy's widened scope reaches a wrap addressed to another key — see ADR-0035
    public function admit(RelayClient $client, FilterCollection $filters, int $heldCount): PolicyRejection|ScopedFilters
    {
        return $this->policy->allowSubscription($client, $filters, $heldCount)
            ?? $this->giftWrapRules->confine(
                $this->policy->filterForClient($client, $filters),
                $this->authenticatedSessions->getAuthenticatedPubkeys($client->getId()),
            );
    }
}
