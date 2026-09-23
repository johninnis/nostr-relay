<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface AuthChallengeInterface
{
    public function getOrCreateChallenge(ClientId $clientId): Challenge;

    public function getChallenge(ClientId $clientId): ?Challenge;
}
