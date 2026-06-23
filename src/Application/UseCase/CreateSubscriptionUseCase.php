<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Entity\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class CreateSubscriptionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly SubscriptionAdmission $admission,
        private readonly ClientMessengerInterface $messenger,
        private readonly DeferredExecutorInterface $deferredExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): void
    {
        // Deliberate: rejections are caught and framed as this message's wire reply here (CLOSED), not centralised in the router — see ADR-0003
        try {
            $modifiedFilters = $this->admission->admit($client, $filters)->getFilters();

            $subscription = Subscription::create($subscriptionId, $modifiedFilters, SubscriptionState::ACTIVE);

            $this->subscriptionManager->addSubscription($client->getId(), $subscription, $filters);

            $this->deferredExecutor->defer(fn () => $this->sendStoredEvents($client, $subscription, $modifiedFilters));
        } catch (PolicyViolationException $e) {
            $this->messenger->send($client, new ClosedMessage($subscriptionId, 'blocked: '.$e->getMessage()));
            $this->logger->warning('Subscription rejected by policy', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'reason' => $e->getMessage(),
                'filters' => array_map(static fn (Filter $filter) => $filter->toArray(), $filters->toArray()),
            ]);
        } catch (RateLimitException) {
            $this->messenger->send($client, new ClosedMessage($subscriptionId, 'rate-limited: slow down'));
        } catch (Throwable $e) {
            $this->subscriptionManager->removeSubscription($client->getId(), $subscriptionId);
            $this->messenger->send($client, new ClosedMessage($subscriptionId, 'error: invalid subscription'));
            $this->logger->error('Subscription creation error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendStoredEvents(RelayClient $client, Subscription $subscription, FilterCollection $filters): void
    {
        try {
            $events = $this->eventStore->findByFilters($filters, 1000);

            foreach ($events as $event) {
                if ($this->policy->canClientReceiveEvent($client, $event)) {
                    $this->messenger->send($client, new EventMessage($subscription->getId(), $event));
                }
            }

            $this->messenger->send($client, new EoseMessage($subscription->getId()));

            $this->subscriptionManager->updateSubscriptionState($client->getId(), $subscription->getId(), SubscriptionState::LIVE);

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
