<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class AuthChallengeIssuer
{
    public function __construct(
        private AuthChallengeInterface $challenges,
    ) {
    }

    public function issue(ClientId $clientId): AuthMessage
    {
        return new AuthMessage($this->challenges->getOrCreateChallenge($clientId));
    }

    public function issueIfUnchallenged(ClientId $clientId): ?AuthMessage
    {
        if (null !== $this->challenges->getChallenge($clientId)) {
            return null;
        }

        return $this->issue($clientId);
    }

    /**
     * @return list<AuthMessage>
     */
    public function offerForRefusal(PolicyRejection $rejection, ClientId $clientId): array
    {
        if (!$rejection->isAuthRequired()) {
            return [];
        }

        $challenge = $this->issueIfUnchallenged($clientId);

        return null === $challenge ? [] : [$challenge];
    }

    /**
     * @return list<AuthMessage>
     */
    public function offerForScope(AdmittedRead $read, ClientId $clientId): array
    {
        return $read->isBeyondScope() ? [$this->issue($clientId)] : [];
    }
}
