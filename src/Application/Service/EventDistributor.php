<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Psr\Log\LoggerInterface;

final class EventDistributor
{
    public function __construct(
        private readonly RelayPolicyInterface $policy,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly ClientManager $clientManager,
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function distributeToSubscribers(Event $event): void
    {
        $subscriptionsWithClients = $this->subscriptionManager->getSubscriptionsForEvent(
            $event->getKind()->toInt()
        );

        if (empty($subscriptionsWithClients)) {
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

        $client = $this->clientManager->getClient($match->getClientId());

        if (!$client instanceof RelayClient) {
            return false;
        }

        if (!$this->policy->canClientReceiveEvent($client, $event)) {
            return false;
        }

        $client->send(new EventMessage($match->getSubscription()->getId(), $event));
        $this->metrics->incrementEventsSent();

        return true;
    }
}
