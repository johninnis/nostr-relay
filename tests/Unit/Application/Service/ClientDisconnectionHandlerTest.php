<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientDisconnectionHandlerTest extends TestCase
{
    private ClientManager $clientManager;
    private SubscriptionManager $subscriptionManager;
    private ClientDisconnectionHandler $handler;

    protected function setUp(): void
    {
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $logger = new NullLogger();

        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);
        $this->clientManager = new ClientManager(
            $this->createMock(SubscriptionLookupInterface::class),
            $metrics,
            $logger,
        );

        $this->handler = new ClientDisconnectionHandler(
            $this->clientManager,
            $this->subscriptionManager,
            $logger,
        );
    }

    public function testDisconnectRemovesClientAndSubscriptions(): void
    {
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connectionInfo = new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now());
        $client = $this->clientManager->registerClient($connection, $connectionInfo);

        $this->handler->disconnect($client->getId());

        $this->assertNull($this->clientManager->getClient($client->getId()));
        $this->assertSame(0, $this->clientManager->getClientCount());
    }

    public function testDisconnectNonExistentClientIsNoOp(): void
    {
        $this->handler->disconnect(ClientId::fromString('unknown'));

        $this->assertSame(0, $this->clientManager->getClientCount());
    }
}
