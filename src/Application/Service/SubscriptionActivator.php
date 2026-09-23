<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Throwable;

final readonly class SubscriptionActivator
{
    // Deliberate: subscription activation coordinates admission, registry, streaming, challenge and deferral — see ADR-0010
    public function __construct(
        private SubscriptionAdmission $admission,
        private SubscriptionRegistryInterface $subscriptionRegistry,
        private StoredEventStreamer $storedEventStreamer,
        private DeferredExecutorInterface $deferredExecutor,
        private AuthChallengeIssuer $authChallengeIssuer,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function activate(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        $admission = $this->admission->admit($client, $filters);

        if ($admission instanceof PolicyRejection) {
            return [new ClosedMessage($subscriptionId, $admission->toWireReason())];
        }

        return $this->register($client, $subscriptionId, $admission);
    }

    /**
     * @return list<RelayMessage>
     */
    public function reactivate(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        $admission = $this->admission->readmit($client, $filters);

        if ($admission instanceof PolicyRejection) {
            // Deliberate: the subscription is already registered, so a CLOSED that left it in place would keep matching events into a subscription the client was told is gone — see ADR-0016
            $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);

            return [new ClosedMessage($subscriptionId, $admission->toWireReason())];
        }

        return $this->register($client, $subscriptionId, $admission);
    }

    /**
     * @return list<RelayMessage>
     */
    private function register(RelayClient $client, SubscriptionId $subscriptionId, ScopedFilters $admission): array
    {
        $modifiedFilters = $admission->getFilters();

        $subscription = Subscription::create($subscriptionId, $modifiedFilters, SubscriptionState::Active);
        $this->subscriptionRegistry->addSubscription($client->getId(), $subscription, $admission->getRequestedFilters());

        try {
            $this->deferredExecutor->defer(fn () => $this->storedEventStreamer->stream($client, $subscription, $modifiedFilters));

            // Deliberate: the AUTH challenge is offered lazily on a scope-exceeding request, never on connect — see ADR-0004
            return $this->authChallengeIssuer->offerForScope($admission, $client->getId());
        } catch (Throwable $e) {
            $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);

            throw $e;
        }
    }
}
