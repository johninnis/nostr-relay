<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\Server;

use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Domain\ValueObject\RelayMetrics;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RelayServerFactoryTest extends TestCase
{
    public function testUsesTheInjectedMetricsCollector(): void
    {
        $snapshot = new RelayMetrics(7, 0, 0, 0, Timestamp::now());

        $collector = $this->createStub(MetricsCollectorInterface::class);
        $collector->method('getMetrics')->willReturn($snapshot);

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getMaxConnections')->willReturn(1000);

        $factory = new RelayServerFactory(
            eventStore: $this->createStub(RelayEventStoreInterface::class),
            policy: $this->createStub(RelayPolicyInterface::class),
            config: $config,
            rateLimitPolicy: $this->createStub(RateLimitPolicyInterface::class),
            authenticationRegistry: new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()),
            logger: new NullLogger(),
            nip11InfoProvider: $this->createStub(Nip11InfoProviderInterface::class),
            signatureService: $this->createStub(SignatureServiceInterface::class),
            metricsCollector: $collector,
        );

        $relay = $factory->create();

        $this->assertSame($snapshot, $relay->getMetrics());
    }
}
