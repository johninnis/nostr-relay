<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface AuthenticatedSessionsInterface
{
    public function authenticate(ClientId $clientId, PublicKey $pubkey): void;

    public function getAuthenticatedPubkeys(ClientId $clientId): PublicKeyCollection;

    public function removeClient(ClientId $clientId): void;
}
