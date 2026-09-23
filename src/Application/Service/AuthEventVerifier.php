<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Service\Nip42ValidatorInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class AuthEventVerifier
{
    public function __construct(
        private RelayConfigInterface $config,
        private RelayPolicyInterface $policy,
        private Nip42ValidatorInterface $validator,
    ) {
    }

    // Deliberate: answers from the event alone, so it can be asked before the signature is verified and a frame that names no live challenge costs nothing — see ADR-0018
    public function verifyClaim(Event $event, Challenge $challenge): ?PolicyRejection
    {
        $failure = $this->validator->validate($event, $challenge, $this->config->getRelayUrl());

        return null === $failure ? null : PolicyRejection::authRequired($failure->message());
    }

    // Deliberate: asked only once the signature has verified, so a refusal cannot tell an unauthenticated sender whose keys this relay trusts — see ADR-0018
    public function verifyIdentity(Event $event): ?PolicyRejection
    {
        return $this->policy->allowsAuthentication($event->getPubkey());
    }
}
