<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;

final class ConnectionInfoTest extends TestCase
{
    private const int CONNECTED_AT = 1700000000;

    public function testKeepsTheIpAddress(): void
    {
        $this->assertSame('192.168.1.1', (string) $this->info()->getIpAddress());
    }

    public function testKeepsTheUserAgent(): void
    {
        $this->assertSame('TestAgent/1.0', $this->info()->getUserAgent());
    }

    public function testKeepsTheConnectionTime(): void
    {
        $this->assertTrue(Timestamp::fromInt(self::CONNECTED_AT)->equals($this->info()->getConnectedAt()));
    }

    public function testPreservesEmptyUserAgent(): void
    {
        $info = new ConnectionInfo(IpAddress::fromString('127.0.0.1'), '', Timestamp::now());

        $this->assertSame('', $info->getUserAgent());
    }

    private function info(): ConnectionInfo
    {
        return new ConnectionInfo(IpAddress::fromString('192.168.1.1'), 'TestAgent/1.0', Timestamp::fromInt(self::CONNECTED_AT));
    }
}
