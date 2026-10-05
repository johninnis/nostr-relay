<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Service\CappedClientRegistry;
use Innis\Nostr\Relay\Application\Service\ClientRegistryInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;

final class CappedClientRegistryTest extends TestCase
{
    public function testRegistrationUnderTheCapIsDelegated(): void
    {
        $inner = $this->createStub(ClientRegistryInterface::class);
        $inner->method('getClientCount')->willReturn(1);
        $client = $this->relayClient();
        $inner->method('registerClient')->willReturn($client);

        $registry = new CappedClientRegistry($inner, 2);

        $this->assertSame($client, $registry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            $this->connectionInfo(),
        ));
    }

    public function testRegistrationAtTheCapIsRefusedAsConnectionLimitReached(): void
    {
        $inner = $this->createStub(ClientRegistryInterface::class);
        $inner->method('getClientCount')->willReturn(2);

        $registry = new CappedClientRegistry($inner, 2);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection limit reached: 2');

        $registry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            $this->connectionInfo(),
        );
    }

    public function testTheCapIsDerivedFromTheLiveCountSoARemovedClientFreesASlot(): void
    {
        $inner = $this->createStub(ClientRegistryInterface::class);
        $inner->method('getClientCount')->willReturn(2, 1);
        $client = $this->relayClient();
        $inner->method('registerClient')->willReturn($client);

        $registry = new CappedClientRegistry($inner, 2);

        try {
            $registry->registerClient($this->createStub(ClientConnectionInterface::class), $this->connectionInfo());
            $this->fail('expected the first registration to be refused');
        } catch (ConnectionException) {
        }

        $this->assertSame($client, $registry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            $this->connectionInfo(),
        ));
    }

    private function connectionInfo(): ConnectionInfo
    {
        return new ConnectionInfo(IpAddress::fromString('10.0.0.1'), 'cap-test', Timestamp::now());
    }

    private function relayClient(): RelayClient
    {
        return new RelayClient(ClientId::fromString('client-1'), $this->connectionInfo());
    }
}
