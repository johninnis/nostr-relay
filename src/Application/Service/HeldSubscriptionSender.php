<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Throwable;

// Deliberate: every frame asks whether the client still holds the subscription, because a send can suspend and a REQ or CLOSE can arrive meanwhile — see ADR-0034
final readonly class HeldSubscriptionSender
{
    public function __construct(
        private ClientMessengerInterface $messenger,
        private SubscriptionLookupInterface $subscriptionLookup,
        private SubscriptionAnswers $subscriptionAnswers,
    ) {
    }

    public function sendEvents(RelayClient $client, Subscription $subscription, StoredEventCollection $events): void
    {
        foreach ($events as $event) {
            if (!$this->holds($client, $subscription)) {
                return;
            }

            $this->messenger->sendEvent($client, $subscription->getId(), $event->getEncoded());
        }
    }

    public function sendEnd(RelayClient $client, Subscription $subscription, ScopedFilters $admission): void
    {
        if (!$this->holds($client, $subscription)) {
            return;
        }

        foreach ($this->subscriptionAnswers->endOfStoredEvents($client, $subscription->getId(), $admission) as $message) {
            if (!$this->holds($client, $subscription)) {
                return;
            }

            $this->messenger->send($client, $message);
        }
    }

    // Deliberate: sent without asking, because the settlement has already withdrawn the subscription it answers, and answers nothing for one the client no longer held — see ADR-0034
    public function sendFault(RelayClient $client, Subscription $subscription, Throwable $fault): void
    {
        foreach ($this->subscriptionAnswers->settleFault($client, $subscription, $fault) as $message) {
            $this->messenger->send($client, $message);
        }
    }

    private function holds(RelayClient $client, Subscription $subscription): bool
    {
        return $this->subscriptionLookup->holds($client->getId(), $subscription);
    }
}
