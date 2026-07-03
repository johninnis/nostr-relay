<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Override;

/**
 * @extends TypedCollection<RelayClient>
 */
final class RelayClientCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return RelayClient::class;
    }

    public function get(ClientId $clientId): ?RelayClient
    {
        return array_find(
            $this->items,
            static fn (RelayClient $client): bool => $client->getId()->equals($clientId),
        );
    }

    public function has(ClientId $clientId): bool
    {
        return array_any(
            $this->items,
            static fn (RelayClient $client): bool => $client->getId()->equals($clientId),
        );
    }
}
