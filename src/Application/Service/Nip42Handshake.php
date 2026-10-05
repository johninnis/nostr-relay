<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class Nip42Handshake
{
    public function __construct(
        private AuthenticationRegistryInterface $authRegistry,
        private AuthEventVerifier $verifier,
        private AuthChallengeIssuer $authChallengeIssuer,
    ) {
    }

    public function verifyClaim(RelayClient $client, Event $event): AuthMessage|PolicyRejection|null
    {
        $challenge = $this->authRegistry->getChallenge($client->getId());

        if (null === $challenge) {
            return $this->authChallengeIssuer->issue($client->getId());
        }

        return $this->verifier->verifyClaim($event, $challenge);
    }

    public function authenticate(RelayClient $client, Event $event): PolicyRejection|PublicKey
    {
        $rejection = $this->verifier->verifyIdentity($event);

        if (null !== $rejection) {
            return $rejection;
        }

        $this->authRegistry->authenticate($client->getId(), $event->getPubkey());

        return $event->getPubkey();
    }
}
