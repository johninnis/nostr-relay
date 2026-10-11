<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpAddressTest extends TestCase
{
    public function testParsesIpv4(): void
    {
        $this->assertSame('192.168.1.1', (string) IpAddress::fromString('192.168.1.1'));
    }

    public function testParsesIpv6(): void
    {
        $this->assertSame('2001:db8::1', (string) IpAddress::fromString('2001:db8::1'));
    }

    public function testIpv6IsIpv6(): void
    {
        $this->assertTrue(IpAddress::fromString('2001:db8::1')->isIpV6());
    }

    public function testIpv4IsNotIpv6(): void
    {
        $this->assertFalse(IpAddress::fromString('10.0.0.1')->isIpV6());
    }

    #[DataProvider('invalidAddresses')]
    public function testTryFromStringReturnsNullForInvalidInput(string $input): void
    {
        $this->assertNull(IpAddress::tryFromString($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'not an address' => ['not-an-ip'];
        yield 'empty' => [''];
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

    public function testAddressesOfTheSameValueAreEqual(): void
    {
        $this->assertTrue(IpAddress::fromString('10.0.0.1')->equals(IpAddress::fromString('10.0.0.1')));
    }

    public function testAddressesOfDifferentValuesAreNotEqual(): void
    {
        $this->assertFalse(IpAddress::fromString('10.0.0.1')->equals(IpAddress::fromString('10.0.0.2')));
    }
}
