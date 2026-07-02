<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Exception;

use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Exception\RelayException;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionExceptionTest extends TestCase
{
    public function testIsCaughtAsRelayException(): void
    {
        $this->expectException(RelayException::class);

        throw new ConnectionException('test');
    }

    public function testGetIpAddressReturnsNullByDefault(): void
    {
        $exception = new ConnectionException('test');

        $this->assertNull($exception->getIpAddress());
    }

    public function testGetIpAddressReturnsProvidedValue(): void
    {
        $ipAddress = IpAddress::fromString('10.0.0.1');
        $exception = new ConnectionException('test', ipAddress: $ipAddress);

        $this->assertSame($ipAddress, $exception->getIpAddress());
    }

    public function testMaxConnectionsReachedIncludesIpInMessage(): void
    {
        $ipAddress = IpAddress::fromString('192.168.1.1');
        $exception = ConnectionException::maxConnectionsReached($ipAddress);

        $this->assertStringContainsString('192.168.1.1', $exception->getMessage());
        $this->assertSame($ipAddress, $exception->getIpAddress());
    }

    public function testBindFailedIncludesHostAndPort(): void
    {
        $exception = ConnectionException::bindFailed('0.0.0.0', 8080);

        $this->assertStringContainsString('0.0.0.0', $exception->getMessage());
        $this->assertStringContainsString('8080', $exception->getMessage());
    }

    public function testBindFailedIncludesPreviousExceptionMessage(): void
    {
        $previous = new RuntimeException('Address already in use');
        $exception = ConnectionException::bindFailed('0.0.0.0', 8080, $previous);

        $this->assertStringContainsString('Address already in use', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidPortIncludesPortAndRange(): void
    {
        $exception = ConnectionException::invalidPort(70000);

        $this->assertStringContainsString('70000', $exception->getMessage());
        $this->assertStringContainsString('between 0 and 65535', $exception->getMessage());
    }
}
