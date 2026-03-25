<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\TestCase;

final class ConnectionInfoTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $timestamp = Timestamp::fromInt(1700000000);
        $info = new ConnectionInfo('192.168.1.1', 'TestAgent/1.0', $timestamp);

        $this->assertSame('192.168.1.1', $info->getIpAddress());
        $this->assertSame('TestAgent/1.0', $info->getUserAgent());
        $this->assertTrue($timestamp->equals($info->getConnectedAt()));
    }

    public function testPreservesEmptyValues(): void
    {
        $info = new ConnectionInfo('', '', Timestamp::now());

        $this->assertSame('', $info->getIpAddress());
        $this->assertSame('', $info->getUserAgent());
    }
}
