<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EventAdmitted;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class EventAdmission
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimiterInterface $rateLimiter,
        private EventValidatorInterface $eventValidator,
    ) {
    }

    public function admit(RelayClient $client, Event $event): PolicyRejection|EventAdmitted
    {
        if (!$this->policy->isRateLimitExempt($client)
            && !$this->rateLimiter->tryConsume($client->getConnectionInfo()->getIpAddress())) {
            return PolicyRejection::rateLimited('slow down');
        }

        $this->eventValidator->validateEvent($event);

        $rejection = $this->policy->allowEventSubmission($client, $event);
        if (null !== $rejection) {
            return $rejection;
        }

        return new EventAdmitted($this->policy->offersAuthChallenge($client, $event));
    }
}
