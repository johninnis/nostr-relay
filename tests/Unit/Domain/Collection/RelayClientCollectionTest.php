<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Collection;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Collection\RelayClientCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RelayClientCollectionTest extends TestCase
{
    private function createClient(string $id): RelayClient
    {
        return new RelayClient(
            ClientId::fromString($id),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testEmptyCollectionHasZeroCount(): void
    {
        $collection = new RelayClientCollection();

        $this->assertSame(0, $collection->count());
        $this->assertTrue($collection->isEmpty());
    }

    public function testConstructorRejectsNonRelayClientItems(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RelayClientCollection(['not-a-client']);
    }

    public function testGetReturnsClientById(): void
    {
        $client = $this->createClient('client-1');
        $collection = new RelayClientCollection([$client]);

        $found = $collection->get(ClientId::fromString('client-1'));

        $this->assertNotNull($found);
        $this->assertTrue($found->getId()->equals(ClientId::fromString('client-1')));
    }

    public function testGetReturnsNullForMissingClient(): void
    {
        $collection = new RelayClientCollection();

        $this->assertNull($collection->get(ClientId::fromString('missing')));
    }

    public function testHasReturnsTrueForExistingClient(): void
    {
        $client = $this->createClient('client-1');
        $collection = new RelayClientCollection([$client]);

        $this->assertTrue($collection->has(ClientId::fromString('client-1')));
    }

    public function testHasReturnsFalseForMissingClient(): void
    {
        $collection = new RelayClientCollection();

        $this->assertFalse($collection->has(ClientId::fromString('missing')));
    }

    public function testToArrayReturnsAllClients(): void
    {
        $client1 = $this->createClient('client-1');
        $client2 = $this->createClient('client-2');
        $collection = new RelayClientCollection([$client1, $client2]);

        $this->assertCount(2, $collection->toArray());
    }

    public function testIsIterableOverClients(): void
    {
        $client1 = $this->createClient('client-1');
        $client2 = $this->createClient('client-2');
        $collection = new RelayClientCollection([$client1, $client2]);

        $iterated = [];
        foreach ($collection as $client) {
            $iterated[] = (string) $client->getId();
        }

        $this->assertSame(['client-1', 'client-2'], $iterated);
    }
}
