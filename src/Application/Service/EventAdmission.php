<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;

final readonly class EventAdmission
{
    public function __construct(
        private RateLimitGate $rateLimitGate,
        private EventValidityGate $validityGate,
        private PublishingGate $publishingGate,
    ) {
    }

    public function admit(RelayClient $client, Event $event): PublishingAnswer
    {
        $rejection = $this->rateLimitGate->admit($client)
            ?? $this->validityGate->admit($event);

        return null === $rejection
            ? $this->publishingGate->admit($client, $event)
            : PublishingAnswer::refused($rejection);
    }
}
