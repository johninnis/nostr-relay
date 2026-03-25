<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Entity;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Entity\RelayClientCollection;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RelayClientCollectionTest extends TestCase
{
    private function createClient(string $id): RelayClient
    {
        return new RelayClient(
            ClientId::fromString($id),
            $this->createMock(ClientConnectionInterface::class),
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->createMock(SubscriptionLookupInterface::class),
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

    public function testAddReturnsNewCollectionWithClient(): void
    {
        $collection = new RelayClientCollection();
        $client = $this->createClient('client-1');

        $updated = $collection->add($client);

        $this->assertSame(0, $collection->count());
        $this->assertSame(1, $updated->count());
    }

    public function testRemoveReturnsNewCollectionWithoutClient(): void
    {
        $client1 = $this->createClient('client-1');
        $client2 = $this->createClient('client-2');
        $collection = new RelayClientCollection([$client1, $client2]);

        $updated = $collection->remove(ClientId::fromString('client-1'));

        $this->assertSame(2, $collection->count());
        $this->assertSame(1, $updated->count());
        $this->assertFalse($updated->has(ClientId::fromString('client-1')));
        $this->assertTrue($updated->has(ClientId::fromString('client-2')));
    }

    public function testRemoveNonExistentClientReturnsEquivalentCollection(): void
    {
        $client = $this->createClient('client-1');
        $collection = new RelayClientCollection([$client]);

        $updated = $collection->remove(ClientId::fromString('missing'));

        $this->assertSame(1, $updated->count());
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
            assert($client instanceof RelayClient);
            $iterated[] = $client->getId()->toString();
        }

        $this->assertSame(['client-1', 'client-2'], $iterated);
    }
}
