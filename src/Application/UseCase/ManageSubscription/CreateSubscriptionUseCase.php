<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ManageSubscription;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

final class CreateSubscriptionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly SubscriptionManager $subscriptionManager,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(RelayClient $client, SubscriptionId $subscriptionId, array $filters): void
    {
        try {
            $this->rateLimiter->checkLimit($client->getConnectionInfo()->getIpAddress());

            $maxSubscriptions = $this->policy->getMaxSubscriptionsPerClient();
            if ($client->getSubscriptionCount() >= $maxSubscriptions) {
                throw new PolicyViolationException("Too many subscriptions (max {$maxSubscriptions})");
            }

            $this->policy->allowSubscription($client, $filters);

            $modifiedFilters = $this->policy->filterForClient($client, $filters);

            $subscription = Subscription::create($subscriptionId, $modifiedFilters)
                ->withState(SubscriptionState::ACTIVE);

            $this->subscriptionManager->addSubscription($client->getId(), $subscription);

            async(function () use ($client, $subscription, $modifiedFilters) {
                $this->sendStoredEvents($client, $subscription, $modifiedFilters);
            });
        } catch (PolicyViolationException $e) {
            $client->send(new ClosedMessage($subscriptionId, 'blocked: '.$e->getMessage()));
            $this->logger->warning('Subscription rejected by policy', [
                'client_id' => $client->getId()->toString(),
                'subscription_id' => (string) $subscriptionId,
                'reason' => $e->getMessage(),
            ]);
        } catch (RateLimitException $e) {
            $client->send(new ClosedMessage($subscriptionId, 'rate-limited: slow down'));
        } catch (Throwable $e) {
            $this->subscriptionManager->removeSubscription($client->getId(), $subscriptionId);
            $client->send(new ClosedMessage($subscriptionId, 'error: invalid subscription'));
            $this->logger->error('Subscription creation error', [
                'client_id' => $client->getId()->toString(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendStoredEvents(RelayClient $client, Subscription $subscription, array $filters): void
    {
        try {
            $events = $this->eventStore->findByFilters($filters, 1000);

            foreach ($events as $event) {
                if ($this->policy->canClientReceiveEvent($client, $event)) {
                    $client->send(new EventMessage($subscription->getId(), $event));
                }
            }

            $client->send(new EoseMessage($subscription->getId()));

            $this->subscriptionManager->updateSubscriptionState($client->getId(), $subscription->getId(), SubscriptionState::LIVE);

            $this->logger->debug('Stored events sent, subscription now live', [
                'subscription_id' => $subscription->getId()->toString(),
                'event_count' => count($events),
            ]);
        } catch (Throwable $e) {
            $client->send(new NoticeMessage('error: failed to fetch events'));
            $this->logger->error('Failed to fetch stored events', [
                'subscription_id' => $subscription->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
