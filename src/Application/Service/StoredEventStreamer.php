<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class StoredEventStreamer
{
    private const int MAX_STORED_EVENTS = 1000;

    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private RelayPolicyInterface $policy,
        private ClientMessengerInterface $messenger,
        private SubscriptionRegistryInterface $subscriptionRegistry,
        private LoggerInterface $logger,
    ) {
    }

    public function stream(RelayClient $client, Subscription $subscription, FilterCollection $filters): void
    {
        try {
            $events = $this->eventStore->findByFilters($filters, self::MAX_STORED_EVENTS);

            foreach ($events as $event) {
                if ($this->policy->canClientReceiveEvent($client, $event)) {
                    $this->messenger->send($client, new EventMessage($subscription->getId(), $event));
                }
            }

            $this->messenger->send($client, new EoseMessage($subscription->getId()));

            $this->subscriptionRegistry->updateSubscriptionState($client->getId(), $subscription->getId(), SubscriptionState::Live);

            $this->logger->debug('Stored events sent, subscription now live', [
                'subscription_id' => (string) $subscription->getId(),
                'event_count' => count($events),
            ]);
        } catch (ConnectionException $e) {
            $this->logger->debug('Subscriber disconnected before stored events finished streaming', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscription->getId(),
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch stored events', [
                'subscription_id' => (string) $subscription->getId(),
                'error' => $e->getMessage(),
            ]);

            try {
                $this->messenger->send($client, new NoticeMessage('error: failed to fetch events'));
            } catch (ConnectionException) {
            }
        }
    }
}
