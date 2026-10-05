<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class EventValidityGate
{
    public function __construct(
        private EventValidatorInterface $eventValidator,
        private ClockInterface $clock,
    ) {
    }

    // Deliberate: one instant judges both validity and expiry, read from the injected clock, never the wall clock — see ADR-0014 and ADR-0020
    public function admit(Event $event): ?PolicyRejection
    {
        $now = $this->clock->now();
        $this->eventValidator->validateEvent($event, $now);

        if ($event->isExpiredAt($now)) {
            return PolicyRejection::invalid('event has expired');
        }

        return null;
    }
}
