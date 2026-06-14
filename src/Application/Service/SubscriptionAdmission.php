<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class SubscriptionAdmission
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimiterInterface $rateLimiter,
        private AuthenticationManager $authManager,
        private ClientManager $clientManager,
    ) {
    }

    public function admit(RelayClient $client, array $filters): ScopedFilters
    {
        if (!$this->policy->isRateLimitExempt($client)) {
            $this->rateLimiter->checkLimit($client->getConnectionInfo()->getIpAddress());
        }

        $this->policy->allowSubscription($client, $filters);

        $scopedFilters = $this->policy->filterForClient($client, $filters);

        if ($scopedFilters->isBeyondScope()) {
            $this->clientManager->send($client, new NoticeMessage('limited to readable scope: authenticate for full access'));

            if (null === $this->authManager->getChallenge($client->getId())) {
                $this->clientManager->send($client, new AuthMessage($this->authManager->getOrCreateChallenge($client->getId())));
            }
        }

        return $scopedFilters;
    }
}
