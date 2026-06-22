<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
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

    public function add(RelayClient $client): self
    {
        return new self([
            ...array_filter(
                $this->items,
                static fn (RelayClient $existing): bool => !$existing->getId()->equals($client->getId()),
            ),
            $client,
        ]);
    }

    public function remove(ClientId $clientId): self
    {
        return new self(array_filter(
            $this->items,
            static fn (RelayClient $client): bool => !$client->getId()->equals($clientId),
        ));
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
