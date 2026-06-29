<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
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
        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $this->subscriptionManager = new SubscriptionManager($metrics, $logger);
        $this->useCase = new CloseSubscriptionUseCase($this->subscriptionManager, $logger);

        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testExecuteRemovesSubscription(): void
    {
        $subId = SubscriptionIdMother::from('sub-1');
        $subscription = Subscription::create($subId, new FilterCollection([new Filter()]));
        $this->subscriptionManager->addSubscription($this->client->getId(), $subscription);

        $this->useCase->execute($this->client, $subId);

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testExecuteHandlesNonExistentSubscription(): void
    {
        $this->useCase->execute($this->client, SubscriptionIdMother::from('missing'));

        $this->assertSame(0, $this->subscriptionManager->getSubscriptionCountForClient($this->client->getId()));
    }
}
