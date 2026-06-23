<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;

final class ConnectionInfoTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $timestamp = Timestamp::fromInt(1700000000);
        $info = new ConnectionInfo(IpAddress::fromString('192.168.1.1'), 'TestAgent/1.0', $timestamp);

        $this->assertSame('192.168.1.1', (string) $info->getIpAddress());
        $this->assertSame('TestAgent/1.0', $info->getUserAgent());
        $this->assertTrue($timestamp->equals($info->getConnectedAt()));
    }

    public function testPreservesEmptyUserAgent(): void
    {
        $info = new ConnectionInfo(IpAddress::fromString('127.0.0.1'), '', Timestamp::now());

        $this->assertSame('', $info->getUserAgent());
    }
}
