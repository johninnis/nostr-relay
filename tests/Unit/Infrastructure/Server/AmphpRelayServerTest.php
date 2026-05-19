<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Server;

use Innis\Nostr\Relay\Infrastructure\Server\AmphpRelayServer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AmphpRelayServerTest extends TestCase
{
    /**
     * @param list<string> $proxies
     */
    private static function invokeValidator(array $proxies): void
    {
        $method = new ReflectionMethod(AmphpRelayServer::class, 'validateTrustedProxies');
        $method->invoke(null, $proxies);
    }

    public function testAcceptsEmptyList(): void
    {
        self::invokeValidator([]);

        $this->assertTrue(true);
    }

    public function testAcceptsPlainIpv4(): void
    {
        self::invokeValidator(['10.0.0.1']);

        $this->assertTrue(true);
    }

    public function testAcceptsPlainIpv6(): void
    {
        self::invokeValidator(['2001:db8::1']);

        $this->assertTrue(true);
    }

    public function testAcceptsIpv4Cidr(): void
    {
        self::invokeValidator(['172.18.0.0/24']);

        $this->assertTrue(true);
    }

    public function testAcceptsIpv6Cidr(): void
    {
        self::invokeValidator(['2001:db8::/32']);

        $this->assertTrue(true);
    }

    public function testRejectsHostname(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid trusted proxy address: proxy.example.com');

        self::invokeValidator(['proxy.example.com']);
    }

    public function testRejectsBogusAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::invokeValidator(['not-an-ip']);
    }

    public function testRejectsIpv4MaskOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CIDR mask');

        self::invokeValidator(['10.0.0.0/33']);
    }

    public function testRejectsIpv6MaskOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid CIDR mask');

        self::invokeValidator(['2001:db8::/129']);
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::invokeValidator(['']);
    }
}
