<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Relay\Application\Port\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class SubscriptionAdmission
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimiterInterface $rateLimiter,
        private AuthenticationManager $authManager,
        private ClientMessengerInterface $messenger,
        private SubscriptionLookupInterface $subscriptionLookup,
    ) {
    }

    public function admit(RelayClient $client, FilterCollection $filters): ScopedFilters
    {
        if (!$this->policy->isRateLimitExempt($client)) {
            $this->rateLimiter->checkLimit((string) $client->getConnectionInfo()->getIpAddress());
        }

        $this->policy->allowSubscription(
            $client,
            $filters,
            $this->subscriptionLookup->getSubscriptionCountForClient($client->getId()),
        );

        $scopedFilters = $this->policy->filterForClient($client, $filters);

        // Deliberate: the AUTH challenge is offered lazily on a scope-exceeding request, never on connect — see ADR-0004
        if ($scopedFilters->isBeyondScope()) {
            $this->messenger->send($client, new NoticeMessage('limited to readable scope: authenticate for full access'));
            $this->messenger->send($client, new AuthMessage($this->authManager->getOrCreateChallenge($client->getId())));
        }

        return $scopedFilters;
    }
}
