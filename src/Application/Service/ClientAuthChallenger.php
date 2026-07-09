<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Override;

final readonly class ClientAuthChallenger implements ClientAuthChallengerInterface
{
    public function __construct(
        private AuthChallengeInterface $authChallenge,
        private ClientMessengerInterface $messenger,
    ) {
    }

    #[Override]
    public function offer(RelayClient $client): void
    {
        $this->messenger->send($client, new AuthMessage($this->authChallenge->getOrCreateChallenge($client->getId())));
    }
}
