<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Psr\Log\LoggerInterface;

final class EventDistributor
{
    // Deliberate: fan-out coordinates policy, subscription lookup, registry, messenger and logging — see ADR-0010
    public function __construct(
        private readonly RelayPolicyInterface $policy,
        private readonly SubscriptionLookupInterface $subscriptionLookup,
        private readonly ClientRegistryInterface $registry,
        private readonly ClientMessengerInterface $messenger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function distributeToSubscribers(Event $event): void
    {
        $subscriptionsWithClients = $this->subscriptionLookup->getSubscriptionsForEvent(
            $event->getKind()
        );

        if ($subscriptionsWithClients->isEmpty()) {
            return;
        }

        $distributionCount = 0;

        foreach ($subscriptionsWithClients as $match) {
            if ($this->sendToMatchingClient($match, $event)) {
                ++$distributionCount;
            }
        }

        if ($distributionCount > 0) {
            $this->logger->debug('Event distributed to subscriptions', [
                'event_id' => $event->getId()->toHex(),
                'subscription_count' => $distributionCount,
            ]);
        }
    }

    private function sendToMatchingClient(SubscriptionMatch $match, Event $event): bool
    {
        if (!$match->getSubscription()->matchesEvent($event)) {
            return false;
        }

        $client = $this->registry->getClient($match->getClientId());

        if (!$client instanceof RelayClient) {
            return false;
        }

        if (!$this->policy->canClientReceiveEvent($client, $event)) {
            return false;
        }

        try {
            $this->messenger->send($client, new EventMessage($match->getSubscription()->getId(), $event));
        } catch (ConnectionException $e) {
            $this->logger->debug('Skipping send to disconnected subscriber', [
                'client_id' => (string) $match->getClientId(),
                'subscription_id' => (string) $match->getSubscription()->getId(),
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
