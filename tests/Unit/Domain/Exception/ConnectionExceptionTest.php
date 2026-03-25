<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Exception;

use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\Exception\RelayException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionExceptionTest extends TestCase
{
    public function testExtendsRelayException(): void
    {
        $exception = new ConnectionException('test');

        $this->assertInstanceOf(RelayException::class, $exception);
    }

    public function testGetIpAddressReturnsNullByDefault(): void
    {
        $exception = new ConnectionException('test');

        $this->assertNull($exception->getIpAddress());
    }

    public function testGetIpAddressReturnsProvidedValue(): void
    {
        $exception = new ConnectionException('test', ipAddress: '10.0.0.1');

        $this->assertSame('10.0.0.1', $exception->getIpAddress());
    }

    public function testMaxConnectionsReachedIncludesIpInMessage(): void
    {
        $exception = ConnectionException::maxConnectionsReached('192.168.1.1');

        $this->assertStringContainsString('192.168.1.1', $exception->getMessage());
        $this->assertSame('192.168.1.1', $exception->getIpAddress());
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
}
