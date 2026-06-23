<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function testParsesIpv4(): void
    {
        $this->assertSame('192.168.1.1', (string) IpAddress::fromString('192.168.1.1'));
    }

    public function testParsesIpv6(): void
    {
        $address = IpAddress::fromString('2001:db8::1');

        $this->assertSame('2001:db8::1', (string) $address);
        $this->assertTrue($address->isIpV6());
    }

    public function testIpv4IsNotIpv6(): void
    {
        $this->assertFalse(IpAddress::fromString('10.0.0.1')->isIpV6());
    }

    public function testTryFromStringReturnsNullForInvalidInput(): void
    {
        $this->assertNull(IpAddress::tryFromString('not-an-ip'));
        $this->assertNull(IpAddress::tryFromString(''));
    }

    public function testRejectsAddressCarryingAPort(): void
    {
        $this->assertNull(IpAddress::tryFromString('192.168.1.1:54321'));
    }

    public function testFromStringThrowsOnInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpAddress::fromString('192.168.1.1:54321');
    }

    public function testEqualityComparesValue(): void
    {
        $this->assertTrue(IpAddress::fromString('10.0.0.1')->equals(IpAddress::fromString('10.0.0.1')));
        $this->assertFalse(IpAddress::fromString('10.0.0.1')->equals(IpAddress::fromString('10.0.0.2')));
    }
}
