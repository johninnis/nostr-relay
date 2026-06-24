<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatchCollection;
use Override;
use Psr\Log\LoggerInterface;

final class SubscriptionManager implements SubscriptionLookupInterface
{
    // Deliberate: in-memory single-process subscription registry, not a swappable store — see ADR-0008
    private array $subscriptions = [];
    private array $clientIdByKey = [];
    private array $subscriptionsByKind = [];
    private array $subscriptionsByClient = [];
    private array $originalFiltersByKey = [];

    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function addSubscription(ClientId $clientId, Subscription $subscription, ?FilterCollection $originalFilters = null): void
    {
        $key = $this->compositeKey($clientId, $subscription->getId());
        $clientIdStr = (string) $clientId;

        if (isset($this->subscriptions[$key])) {
            $this->removeSubscription($clientId, $subscription->getId());
        }

        $this->subscriptions[$key] = $subscription;
        $this->clientIdByKey[$key] = $clientId;
        $this->originalFiltersByKey[$key] = $originalFilters ?? FilterCollection::empty();
        $this->addToKindIndex($subscription, $key);
        $this->subscriptionsByClient[$clientIdStr][$key] = true;

        $this->metrics->incrementSubscriptions();

        $this->logger->debug('Subscription created', [
            'subscription_id' => (string) $subscription->getId(),
            'client_id' => $clientIdStr,
            'filter_count' => count($subscription->getFilters()),
        ]);
    }

    public function removeSubscription(ClientId $clientId, SubscriptionId $subscriptionId): void
    {
        $key = $this->compositeKey($clientId, $subscriptionId);

        if (!isset($this->subscriptions[$key])) {
            return;
        }

        $subscription = $this->subscriptions[$key];
        $clientIdStr = (string) $clientId;

        $this->removeFromKindIndex($subscription, $key);
        $this->removeFromClientIndex($clientIdStr, $key);

        unset($this->subscriptions[$key], $this->clientIdByKey[$key], $this->originalFiltersByKey[$key]);
        $this->metrics->decrementSubscriptions();

        $this->logger->debug('Subscription closed', [
            'subscription_id' => (string) $subscriptionId,
            'client_id' => $clientIdStr,
        ]);
    }

    public function removeAllForClient(ClientId $clientId): void
    {
        $clientIdStr = (string) $clientId;
        $keys = array_keys($this->subscriptionsByClient[$clientIdStr] ?? []);

        foreach ($keys as $key) {
            $key = (string) $key;
            if (isset($this->subscriptions[$key])) {
                $this->removeFromKindIndex($this->subscriptions[$key], $key);
                unset($this->subscriptions[$key], $this->clientIdByKey[$key], $this->originalFiltersByKey[$key]);
                $this->metrics->decrementSubscriptions();
            }
        }

        unset($this->subscriptionsByClient[$clientIdStr]);
    }

    public function updateSubscriptionState(ClientId $clientId, SubscriptionId $subscriptionId, SubscriptionState $state): void
    {
        $key = $this->compositeKey($clientId, $subscriptionId);

        if (isset($this->subscriptions[$key])) {
            $this->subscriptions[$key] = $this->subscriptions[$key]->withState($state);
        }
    }

    public function getSubscriptionsForEvent(int $eventKind): SubscriptionMatchCollection
    {
        $keys = ($this->subscriptionsByKind[$eventKind] ?? [])
            + ($this->subscriptionsByKind['*'] ?? []);

        $results = [];
        foreach ($keys as $key => $unused) {
            if (isset($this->subscriptions[$key], $this->clientIdByKey[$key])) {
                $results[] = new SubscriptionMatch($this->clientIdByKey[$key], $this->subscriptions[$key]);
            }
        }

        return new SubscriptionMatchCollection($results);
    }

    #[Override]
    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection
    {
        $keys = array_keys($this->subscriptionsByClient[(string) $clientId] ?? []);

        $subscriptions = [];
        foreach ($keys as $key) {
            if (isset($this->subscriptions[$key])) {
                $subscription = $this->subscriptions[$key];
                $subscriptions[(string) $subscription->getId()] = $subscription;
            }
        }

        return new SubscriptionCollection($subscriptions);
    }

    #[Override]
    public function getSubscriptionCountForClient(ClientId $clientId): int
    {
        return count($this->subscriptionsByClient[(string) $clientId] ?? []);
    }

    public function getOriginalFilters(ClientId $clientId, SubscriptionId $subscriptionId): FilterCollection
    {
        return $this->originalFiltersByKey[$this->compositeKey($clientId, $subscriptionId)] ?? FilterCollection::empty();
    }

    public function getSubscriptionIdsForClient(ClientId $clientId): array
    {
        $ids = [];

        foreach (array_keys($this->subscriptionsByClient[(string) $clientId] ?? []) as $key) {
            $key = (string) $key;
            if (isset($this->subscriptions[$key])) {
                $ids[] = $this->subscriptions[$key]->getId();
            }
        }

        return $ids;
    }

    public function getAllSubscriptions(): SubscriptionCollection
    {
        $subscriptions = [];
        foreach ($this->subscriptions as $subscription) {
            $subscriptions[(string) $subscription->getId()] = $subscription;
        }

        return new SubscriptionCollection($subscriptions);
    }

    private function compositeKey(ClientId $clientId, SubscriptionId $subscriptionId): string
    {
        return (string) $clientId.':'.(string) $subscriptionId;
    }

    private function addToKindIndex(Subscription $subscription, string $key): void
    {
        $indexedKinds = [];

        foreach ($subscription->getFilters() as $filter) {
            if ($filter->hasKinds()) {
                foreach ($filter->getKinds()?->toInts() ?? [] as $kindInt) {
                    if (!isset($indexedKinds[$kindInt])) {
                        $this->subscriptionsByKind[$kindInt][$key] = true;
                        $indexedKinds[$kindInt] = true;
                    }
                }
            } elseif (!isset($indexedKinds['*'])) {
                $this->subscriptionsByKind['*'][$key] = true;
                $indexedKinds['*'] = true;
            }
        }
    }

    private function removeFromKindIndex(Subscription $subscription, string $key): void
    {
        $removedKinds = [];

        foreach ($subscription->getFilters() as $filter) {
            if ($filter->hasKinds()) {
                foreach ($filter->getKinds()?->toInts() ?? [] as $kindInt) {
                    if (!isset($removedKinds[$kindInt])) {
                        $this->removeKindEntry($kindInt, $key);
                        $removedKinds[$kindInt] = true;
                    }
                }
            } elseif (!isset($removedKinds['*'])) {
                $this->removeKindEntry('*', $key);
                $removedKinds['*'] = true;
            }
        }
    }

    private function removeKindEntry(string|int $kind, string $key): void
    {
        unset($this->subscriptionsByKind[$kind][$key]);

        if (empty($this->subscriptionsByKind[$kind])) {
            unset($this->subscriptionsByKind[$kind]);
        }
    }

    private function removeFromClientIndex(string $clientId, string $key): void
    {
        unset($this->subscriptionsByClient[$clientId][$key]);

        if (empty($this->subscriptionsByClient[$clientId])) {
            unset($this->subscriptionsByClient[$clientId]);
        }
    }
}
