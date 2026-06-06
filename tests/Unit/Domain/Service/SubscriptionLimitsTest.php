<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\TestCase;

final class SubscriptionLimitsTest extends TestCase
{
    public function testAllowsSubscriptionWithinEveryLimit(): void
    {
        $limits = new SubscriptionLimits(20, 5, 1000);

        $limits->enforce($this->clientWithSubscriptionCount(0), [new Filter(limit: 500)]);

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsWhenSubscriptionCountReached(): void
    {
        $limits = new SubscriptionLimits(2, 5, 1000);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('too many subscriptions (max 2)');

        $limits->enforce($this->clientWithSubscriptionCount(2), []);
    }

    public function testRejectsWhenTooManyFilters(): void
    {
        $limits = new SubscriptionLimits(20, 1, 1000);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('too many filters (max 1)');

        $limits->enforce($this->clientWithSubscriptionCount(0), [new Filter(), new Filter()]);
    }

    public function testRejectsWhenFilterLimitTooHigh(): void
    {
        $limits = new SubscriptionLimits(20, 5, 100);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('filter limit too high (max 100)');

        $limits->enforce($this->clientWithSubscriptionCount(0), [new Filter(limit: 101)]);
    }

    private function clientWithSubscriptionCount(int $count): RelayClient
    {
        $lookup = $this->createStub(SubscriptionLookupInterface::class);
        $lookup->method('getSubscriptionCountForClient')->willReturn($count);

        return new RelayClient(
            ClientId::fromString('client-1'),
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $lookup,
        );
    }
}
