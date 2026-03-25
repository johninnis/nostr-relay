<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Entity;

use ArrayIterator;
use Countable;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use InvalidArgumentException;
use IteratorAggregate;

final class RelayClientCollection implements IteratorAggregate, Countable
{
    private array $clients;

    public function __construct(array $clients = [])
    {
        $this->clients = [];

        foreach ($clients as $client) {
            if (!$client instanceof RelayClient) {
                throw new InvalidArgumentException('All items must be RelayClient instances');
            }
            $this->clients[$client->getId()->toString()] = $client;
        }
    }

    public function add(RelayClient $client): self
    {
        $copy = clone $this;
        $copy->clients[$client->getId()->toString()] = $client;

        return $copy;
    }

    public function remove(ClientId $clientId): self
    {
        $copy = clone $this;
        unset($copy->clients[$clientId->toString()]);

        return $copy;
    }

    public function get(ClientId $clientId): ?RelayClient
    {
        return $this->clients[$clientId->toString()] ?? null;
    }

    public function has(ClientId $clientId): bool
    {
        return isset($this->clients[$clientId->toString()]);
    }

    public function isEmpty(): bool
    {
        return empty($this->clients);
    }

    public function toArray(): array
    {
        return array_values($this->clients);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator(array_values($this->clients));
    }

    public function count(): int
    {
        return count($this->clients);
    }
}
