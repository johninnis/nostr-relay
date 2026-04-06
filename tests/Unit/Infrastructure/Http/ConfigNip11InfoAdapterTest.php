<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Http;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Infrastructure\Http\ConfigNip11InfoAdapter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigNip11InfoAdapterTest extends TestCase
{
    public function testDelegatesToConfig(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL');
        $nip11Info = new Nip11Info($relayUrl, 'Test Relay');

        $config = $this->createMock(RelayConfigInterface::class);
        $config->expects($this->once())
            ->method('getRelayInfo')
            ->willReturn($nip11Info);

        $adapter = new ConfigNip11InfoAdapter($config);

        $result = $adapter->getNip11Info();

        $this->assertSame($nip11Info, $result);
    }

    public function testReturnsCurrentValueOnEachCall(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid URL');
        $first = new Nip11Info($relayUrl, 'First');
        $second = new Nip11Info($relayUrl, 'Second');

        $config = $this->createMock(RelayConfigInterface::class);
        $config->expects($this->exactly(2))
            ->method('getRelayInfo')
            ->willReturnOnConsecutiveCalls($first, $second);

        $adapter = new ConfigNip11InfoAdapter($config);

        $this->assertSame('First', $adapter->getNip11Info()->getName());
        $this->assertSame('Second', $adapter->getNip11Info()->getName());
    }
}
