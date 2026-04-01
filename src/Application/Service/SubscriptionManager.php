<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Entity\SubscriptionCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Psr\Log\LoggerInterface;

final class SubscriptionManager implements SubscriptionLookupInterface
{
    private array $subscriptions = [];
    private array $clientIdByKey = [];
    private array $subscriptionsByKind = [];
    private array $subscriptionsByClient = [];

    public function __construct(
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function addSubscription(ClientId $clientId, Subscription $subscription): void
    {
        $key = $this->compositeKey($clientId, $subscription->getId());
        $clientIdStr = (string) $clientId;

        if (isset($this->subscriptions[$key])) {
            $this->removeSubscription($clientId, $subscription->getId());
        }

        $this->subscriptions[$key] = $subscription;
        $this->clientIdByKey[$key] = $clientId;
        $this->addToKindIndex($subscription, $key);
        $this->subscriptionsByClient[$clientIdStr][] = $key;

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

        unset($this->subscriptions[$key], $this->clientIdByKey[$key]);
        $this->metrics->decrementSubscriptions();

        $this->logger->debug('Subscription closed', [
            'subscription_id' => (string) $subscriptionId,
            'client_id' => $clientIdStr,
        ]);
    }

    public function removeAllForClient(ClientId $clientId): void
    {
        $clientIdStr = (string) $clientId;
        $keys = $this->subscriptionsByClient[$clientIdStr] ?? [];

        foreach ($keys as $key) {
            if (isset($this->subscriptions[$key])) {
                $this->removeFromKindIndex($this->subscriptions[$key], $key);
                unset($this->subscriptions[$key], $this->clientIdByKey[$key]);
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

    public function getSubscriptionsForEvent(int $eventKind): array
    {
        $keys = array_merge(
            $this->subscriptionsByKind[$eventKind] ?? [],
            $this->subscriptionsByKind['*'] ?? []
        );

        $results = [];
        foreach (array_unique($keys) as $key) {
            if (isset($this->subscriptions[$key], $this->clientIdByKey[$key])) {
                $results[] = new SubscriptionMatch($this->clientIdByKey[$key], $this->subscriptions[$key]);
            }
        }

        return $results;
    }

    public function getSubscriptionsForClient(ClientId $clientId): SubscriptionCollection
    {
        $keys = $this->subscriptionsByClient[(string) $clientId] ?? [];

        $subscriptions = [];
        foreach ($keys as $key) {
            if (isset($this->subscriptions[$key])) {
                $subscription = $this->subscriptions[$key];
                $subscriptions[(string) $subscription->getId()] = $subscription;
            }
        }

        return new SubscriptionCollection($subscriptions);
    }

    public function getSubscriptionCountForClient(ClientId $clientId): int
    {
        return count($this->subscriptionsByClient[(string) $clientId] ?? []);
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
                foreach ($filter->getKinds() as $kind) {
                    if (!isset($indexedKinds[$kind])) {
                        $this->subscriptionsByKind[$kind][] = $key;
                        $indexedKinds[$kind] = true;
                    }
                }
            } elseif (!isset($indexedKinds['*'])) {
                $this->subscriptionsByKind['*'][] = $key;
                $indexedKinds['*'] = true;
            }
        }
    }

    private function removeFromKindIndex(Subscription $subscription, string $key): void
    {
        $removedKinds = [];

        foreach ($subscription->getFilters() as $filter) {
            if ($filter->hasKinds()) {
                foreach ($filter->getKinds() as $kind) {
                    if (!isset($removedKinds[$kind])) {
                        $this->removeKindEntry($kind, $key);
                        $removedKinds[$kind] = true;
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
        if (!isset($this->subscriptionsByKind[$kind])) {
            return;
        }

        $this->subscriptionsByKind[$kind] = array_filter(
            $this->subscriptionsByKind[$kind],
            static fn ($id) => $id !== $key
        );

        if (empty($this->subscriptionsByKind[$kind])) {
            unset($this->subscriptionsByKind[$kind]);
        }
    }

    private function removeFromClientIndex(string $clientId, string $key): void
    {
        if (!isset($this->subscriptionsByClient[$clientId])) {
            return;
        }

        $this->subscriptionsByClient[$clientId] = array_filter(
            $this->subscriptionsByClient[$clientId],
            static fn ($id) => $id !== $key
        );

        if (empty($this->subscriptionsByClient[$clientId])) {
            unset($this->subscriptionsByClient[$clientId]);
        }
    }
}
