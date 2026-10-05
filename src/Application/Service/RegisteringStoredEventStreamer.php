<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Throwable;

final readonly class RegisteringStoredEventStreamer
{
    public function __construct(
        private DeferredExecutorInterface $deferredExecutor,
        private StoredEventStreamer $storedEventStreamer,
        private SubscriptionRegistryInterface $subscriptionRegistry,
    ) {
    }

    public function stream(RelayClient $client, Subscription $subscription, ScopedFilters $admission): void
    {
        $this->subscriptionRegistry->addSubscription($client->getId(), $subscription, $admission->getRequestedFilters());

        try {
            $this->deferredExecutor->defer(fn () => $this->storedEventStreamer->stream($client, $subscription, $admission->getFilters()));
        } catch (Throwable $e) {
            $this->subscriptionRegistry->removeSubscription($client->getId(), $subscription->getId());

            throw $e;
        }
    }
}
