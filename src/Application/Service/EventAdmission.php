<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EventAdmitted;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class EventAdmission
{
    // Deliberate: four collaborators, the fourth a clock because expiry is judged against injected time, never the wall clock — see ADR-0010 and ADR-0014
    public function __construct(
        private RelayPolicyInterface $policy,
        private RateLimitGate $rateLimitGate,
        private EventValidatorInterface $eventValidator,
        private ClockInterface $clock,
    ) {
    }

    public function admit(RelayClient $client, Event $event): PolicyRejection|EventAdmitted
    {
        $rateLimit = $this->rateLimitGate->admit($client);
        if (null !== $rateLimit) {
            return $rateLimit;
        }

        $this->eventValidator->validateEvent($event);

        // Deliberate: an already-expired event is refused at admission and never stored or fanned out — see ADR-0014
        if ($event->isExpiredAt($this->clock->now())) {
            return PolicyRejection::invalid('event has expired');
        }

        $rejection = $this->policy->allowEventSubmission($client, $event);
        if (null !== $rejection) {
            return $rejection;
        }

        return new EventAdmitted($this->policy->offersAuthChallenge($client, $event));
    }
}
