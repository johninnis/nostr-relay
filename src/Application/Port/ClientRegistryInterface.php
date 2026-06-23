<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

interface ClientRegistryInterface
{
    public function getClient(ClientId $clientId): ?RelayClient;

    public function getClientCount(): int;

    public function removeClient(ClientId $clientId): void;

    public function recordEventReceived(ClientId $clientId): void;

    public function recordEventAccepted(ClientId $clientId): void;
}
