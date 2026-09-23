<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use InvalidArgumentException;
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

    public function testAFilterLimitAboveTheCeilingIsClampedRatherThanRefused(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100);

        $bounded = $limits->bound(new FilterCollection([Filter::tryFromArray(['kinds' => [1], 'limit' => 5000])]));

        $this->assertNull($limits->enforce(0, $bounded));
        $this->assertSame([100], array_map(static fn (Filter $filter): ?int => $filter->getLimit(), $bounded->toArray()));
    }

    public function testACeilingAFilterCouldNotCarryIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionLimits(10, 5, Filter::MAX_LIMIT + 1);
    }

    public function testACeilingOfZeroIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionLimits(10, 5, 0);
    }

    public function testAFilterStatingNoLimitIsGivenTheCeiling(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100);

        $bounded = $limits->bound(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]));

        $this->assertSame([100], array_map(static fn (Filter $filter): ?int => $filter->getLimit(), $bounded->toArray()));
    }
}
