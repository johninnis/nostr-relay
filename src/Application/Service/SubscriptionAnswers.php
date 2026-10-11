<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\EoseHint;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class SubscriptionAnswers
{
    public function __construct(
        private SubscriptionRegistryInterface $subscriptionRegistry,
        private AuthChallengeIssuer $authChallengeIssuer,
        private LoggerInterface $logger,
    ) {
    }

    // Deliberate: a REQ replaces whatever subscription its id held, so a refused one withdraws it and the CLOSED it is answered with is true — see ADR-0033
    /**
     * @return list<RelayMessage>
     */
    public function settleRefusal(RelayClient $client, SubscriptionId $subscriptionId, PolicyRejection $rejection): array
    {
        $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);

        return $this->refuseCount($client, $subscriptionId, $rejection);
    }

    // Deliberate: a COUNT registers nothing and replaces nothing, so its refusal leaves a REQ on the same id open — see ADR-0033
    /**
     * @return list<RelayMessage>
     */
    public function refuseCount(RelayClient $client, SubscriptionId $subscriptionId, PolicyRejection $rejection): array
    {
        return [
            ...$this->authChallengeIssuer->offerForRefusal($rejection, $client->getId()),
            $rejection->toClosedMessage($subscriptionId),
        ];
    }

    // Deliberate: a fault settles only the subscription whose read faulted, so one a REQ replaced, or the client closed, is not withdrawn or answered in its place — see ADR-0034
    /**
     * @return list<RelayMessage>
     */
    public function settleFault(RelayClient $client, Subscription $subscription, Throwable $fault): array
    {
        $this->logger->error('Failed to read stored events', [
            'client_id' => (string) $client->getId(),
            'subscription_id' => (string) $subscription->getId(),
            'error' => $fault->getMessage(),
        ]);

        if (!$this->subscriptionRegistry->holds($client->getId(), $subscription)) {
            return [];
        }

        $this->subscriptionRegistry->removeSubscription($client->getId(), $subscription->getId());

        return [ClosedMessage::closed($subscription->getId(), ReasonPrefix::Error, 'could not read stored events')];
    }

    // Deliberate: the AUTH challenge is offered lazily on a scope-exceeding request, never on connect — see ADR-0004
    /**
     * @return list<RelayMessage>
     */
    public function forScope(RelayClient $client, ScopedFilters $scopedFilters): array
    {
        return $this->authChallengeIssuer->offerForScope($scopedFilters, $client->getId());
    }

    // Deliberate: a narrowed read's EOSE carries NIP-67's auth hint, and its challenge is written just before it — see ADR-0004
    /**
     * @return list<RelayMessage>
     */
    public function endOfStoredEvents(RelayClient $client, SubscriptionId $subscriptionId, ScopedFilters $scopedFilters): array
    {
        if (!$scopedFilters->isBeyondScope()) {
            return [new EoseMessage($subscriptionId)];
        }

        return [...$this->forScope($client, $scopedFilters), EoseMessage::hinting($subscriptionId, EoseHint::Auth)];
    }
}
