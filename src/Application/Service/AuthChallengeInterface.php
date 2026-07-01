<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface AuthChallengeInterface
{
    public function getOrCreateChallenge(ClientId $clientId): string;

    public function getChallenge(ClientId $clientId): ?string;
}
