<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Entity;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\TestCase;

final class RelayClientTest extends TestCase
{
    private ClientId $clientId;
    private ConnectionInfo $connectionInfo;

    protected function setUp(): void
    {
        $this->clientId = ClientId::fromString('test-client');
        $this->connectionInfo = new ConnectionInfo('127.0.0.1', 'TestAgent/1.0', Timestamp::now());
    }

    private function makeClient(): RelayClient
    {
        return new RelayClient($this->clientId, $this->connectionInfo);
    }

    public function testGetIdReturnsClientId(): void
    {
        $this->assertTrue($this->clientId->equals($this->makeClient()->getId()));
    }

    public function testGetConnectionInfoReturnsConnectionInfo(): void
    {
        $this->assertSame($this->connectionInfo, $this->makeClient()->getConnectionInfo());
    }
}
