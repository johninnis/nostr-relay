<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use PHPUnit\Framework\TestCase;

final class SubscriptionLimitsTest extends TestCase
{
    public function testAllowsSubscriptionWithinEveryLimit(): void
    {
        $limits = new SubscriptionLimits(20, 5, 1000);

        $limits->enforce(0, new FilterCollection([new Filter(limit: 500)]));

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsWhenSubscriptionCountReached(): void
    {
        $limits = new SubscriptionLimits(2, 5, 1000);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('too many subscriptions (max 2)');

        $limits->enforce(2, FilterCollection::empty());
    }

    public function testRejectsWhenTooManyFilters(): void
    {
        $limits = new SubscriptionLimits(20, 1, 1000);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('too many filters (max 1)');

        $limits->enforce(0, new FilterCollection([new Filter(), new Filter()]));
    }

    public function testRejectsWhenFilterLimitTooHigh(): void
    {
        $limits = new SubscriptionLimits(20, 5, 100);

        $this->expectException(PolicyViolationException::class);
        $this->expectExceptionMessage('filter limit too high (max 100)');

        $limits->enforce(0, new FilterCollection([new Filter(limit: 101)]));
    }
}
