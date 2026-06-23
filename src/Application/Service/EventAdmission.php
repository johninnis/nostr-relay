<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

final readonly class EventAdmission
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimiterInterface $rateLimiter,
        private EventValidatorInterface $eventValidator,
    ) {
    }

    public function admit(RelayClient $client, Event $event): void
    {
        if (!$this->policy->isRateLimitExempt($client)) {
            $this->rateLimiter->checkLimit((string) $client->getConnectionInfo()->getIpAddress());
        }

        $this->eventValidator->validateEvent($event);

        $this->policy->allowEventSubmission($client, $event);
    }
}
