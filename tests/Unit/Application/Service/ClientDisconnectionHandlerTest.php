<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientDisconnectionHandlerTest extends TestCase
{
    private InMemoryClientRegistry $clientRegistry;
    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private ClientDisconnectionHandler $handler;

    protected function setUp(): void
    {
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $this->clientRegistry = new InMemoryClientRegistry(
            $metrics,
            new NativeRandomBytesGenerator(),
            $logger,
        );

        $this->handler = new ClientDisconnectionHandler(
            $this->clientRegistry,
            $this->subscriptionRegistry,
            new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator()),
            $logger,
        );
    }

    public function testDisconnectRemovesClientAndSubscriptions(): void
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connectionInfo = new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now());
        $client = $this->clientRegistry->registerClient($connection, $connectionInfo);

        $this->handler->disconnect($client->getId());

        $this->assertNull($this->clientRegistry->getClient($client->getId()));
        $this->assertSame(0, $this->clientRegistry->getClientCount());
    }

    public function testDisconnectNonExistentClientIsNoOp(): void
    {
        $this->handler->disconnect(ClientId::fromString('unknown'));

        $this->assertSame(0, $this->clientRegistry->getClientCount());
    }
}
