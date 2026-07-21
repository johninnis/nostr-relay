<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use PHPUnit\Framework\TestCase;

final class SubscriptionLimitsTest extends TestCase
{
    public function testAllowsSubscriptionWithinEveryLimit(): void
    {
        $limits = new SubscriptionLimits(20, 5, 1000);

        $this->assertNull($limits->enforce(0, new FilterCollection([new Filter(limit: 500)])));
    }

    public function testRejectsWhenSubscriptionCountReached(): void
    {
        $limits = new SubscriptionLimits(2, 5, 1000);

        $rejection = $limits->enforce(2, new FilterCollection());

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('too many subscriptions (max 2)', $rejection->toWireReason());
    }

    public function testRejectsWhenTooManyFilters(): void
    {
        $limits = new SubscriptionLimits(20, 1, 1000);

        $rejection = $limits->enforce(0, new FilterCollection([new Filter(), new Filter()]));

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('too many filters (max 1)', $rejection->toWireReason());
    }

    public function testRejectsWhenFilterLimitTooHigh(): void
    {
        $limits = new SubscriptionLimits(20, 5, 100);

        $rejection = $limits->enforce(0, new FilterCollection([new Filter(limit: 101)]));

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('filter limit too high (max 100)', $rejection->toWireReason());
    }
}
