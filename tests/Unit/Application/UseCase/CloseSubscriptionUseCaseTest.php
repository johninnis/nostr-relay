<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CloseSubscriptionUseCaseTest extends TestCase
{
    private SubscriptionManager $subscriptionManager;
    private CloseSubscriptionUseCase $useCase;
    private RelayClient $client;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $metrics = $this->createMock(MetricsCollectorInterface::class);
        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);
        $this->useCase = new CloseSubscriptionUseCase($this->subscriptionManager, $logger);

        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            $this->createMock(ClientConnectionInterface::class),
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->createMock(SubscriptionLookupInterface::class),
        );
    }

    public function testExecuteRemovesSubscription(): void
    {
        $subId = SubscriptionId::fromString('sub-1');
        $subscription = Subscription::create($subId, [new Filter()]);
        $this->subscriptionManager->addSubscription($this->client->getId(), $subscription);

        $this->useCase->execute($this->client, $subId);

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testExecuteHandlesNonExistentSubscription(): void
    {
        $this->useCase->execute($this->client, SubscriptionId::fromString('missing'));

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }
}
