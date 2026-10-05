<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;

final readonly class PublishingGate
{
    private const string PROTECTED_EVENT_REASON = 'this event may only be published by its author';

    // Deliberate: the challenge accompanies the answer it decided, so a caller never pairs an outcome with a challenge itself — see ADR-0029
    public function __construct(
        private RelayPolicyInterface $policy,
        private AuthenticatedSessionsInterface $authenticatedSessions,
        private AuthChallengeIssuer $authChallengeIssuer,
    ) {
    }

    public function admit(RelayClient $client, Event $event): PublishingAnswer
    {
        $rejection = $this->policy->allowEventSubmission($client, $event) ?? $this->refuseUnlessPublishedByAuthor($client, $event);
        if (null !== $rejection) {
            $challenges = $this->authChallengeIssuer->offerForRefusal($rejection, $client->getId());

            return PublishingAnswer::refused($rejection, $challenges[0] ?? null);
        }

        $challenge = $this->policy->offersAuthChallenge($client, $event)
            ? $this->authChallengeIssuer->issue($client->getId())
            : null;

        return PublishingAnswer::admitted($challenge);
    }

    // Deliberate: applied after every policy, so no policy's bypass can admit a protected event from anyone but its authenticated author — see ADR-0023
    private function refuseUnlessPublishedByAuthor(RelayClient $client, Event $event): ?PolicyRejection
    {
        if (!$event->isProtected()) {
            return null;
        }

        $authenticatedPubkeys = $this->authenticatedSessions->getAuthenticatedPubkeys($client->getId());

        if ($authenticatedPubkeys->contains($event->getPubkey())) {
            return null;
        }

        return $authenticatedPubkeys->isEmpty()
            ? PolicyRejection::authRequired(self::PROTECTED_EVENT_REASON)
            : PolicyRejection::restricted(self::PROTECTED_EVENT_REASON);
    }
}
