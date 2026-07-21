<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class RateLimitGate
{
    public function __construct(
        private RateLimiterInterface $rateLimiter,
        private RelayPolicyInterface $policy,
    ) {
    }

    public function admit(RelayClient $client): ?PolicyRejection
    {
        if ($this->policy->isRateLimitExempt($client)) {
            return null;
        }

        if ($this->rateLimiter->tryConsume($client->getConnectionInfo()->getIpAddress())) {
            return null;
        }

        return PolicyRejection::rateLimited('slow down');
    }
}
