<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

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
     * @return list<RelayMessage>
     */
    public function offerForScope(ScopedFilters $scopedFilters, ClientId $clientId): array
    {
        if (!$scopedFilters->isBeyondScope()) {
            return [];
        }

        return [
            NoticeMessage::fromString('limited to readable scope: authenticate for full access'),
            $this->issue($clientId),
        ];
    }
}
