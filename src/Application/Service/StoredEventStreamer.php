<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Throwable;

final readonly class StoredEventStreamer
{
    public function __construct(
        private StoredEventReadGate $readGate,
        private HeldSubscriptionSender $sender,
    ) {
    }

    public function stream(RelayClient $client, Subscription $subscription, AdmittedRead $admission): void
    {
        // Deliberate: the one fault boundary for a deferred read of stored events, which no client message's handler is still there to frame — see ADR-0034
        try {
            $events = $this->readGate->readableFor($client, $admission);
        } catch (Throwable $fault) {
            $this->sender->sendFault($client, $subscription, $fault);

            return;
        }

        $this->sender->sendEvents($client, $subscription, $events);
        $this->sender->sendEnd($client, $subscription, $admission);
    }
}
