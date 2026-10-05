<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class SubscriptionAnswers
{
    public function __construct(
        private SubscriptionRegistryInterface $subscriptionRegistry,
        private AuthChallengeIssuer $authChallengeIssuer,
    ) {
    }

    // Deliberate: a refusal withdraws any registration the id held, so a CLOSED can never leave a subscription matching events the client was told is gone — see ADR-0016
    /**
     * @return list<RelayMessage>
     */
    public function settleRefusal(RelayClient $client, SubscriptionId $subscriptionId, PolicyRejection $rejection): array
    {
        $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);

        return [
            ...$this->authChallengeIssuer->offerForRefusal($rejection, $client->getId()),
            $rejection->toClosedMessage($subscriptionId),
        ];
    }

    // Deliberate: the AUTH challenge is offered lazily on a scope-exceeding request, never on connect — see ADR-0004
    /**
     * @return list<RelayMessage>
     */
    public function forScope(RelayClient $client, ScopedFilters $scopedFilters): array
    {
        return $this->authChallengeIssuer->offerForScope($scopedFilters, $client->getId());
    }
}
