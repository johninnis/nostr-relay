<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionLimits;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SubscriptionLimitsTest extends TestCase
{
    public function testAllowsSubscriptionWithinEveryLimit(): void
    {
        $limits = new SubscriptionLimits(20, 5, 1000, 100);

        $this->assertNull($limits->enforce(0, new FilterCollection([Filter::from(limit: 500)])));
    }

    public function testRejectsWhenSubscriptionCountReached(): void
    {
        $limits = new SubscriptionLimits(2, 5, 1000, 100);

        $rejection = $limits->enforce(2, new FilterCollection());

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('too many subscriptions (max 2)', $rejection->toWireReason());
    }

    public function testRejectsWhenTooManyFilters(): void
    {
        $limits = new SubscriptionLimits(20, 1, 1000, 100);

        $rejection = $limits->enforce(0, new FilterCollection([Filter::from(), Filter::from()]));

        $this->assertNotNull($rejection);
        $this->assertStringContainsString('too many filters (max 1)', $rejection->toWireReason());
    }

    public function testAFilterLimitAboveTheCeilingIsClampedRatherThanRefused(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100, 100);

        $bounded = $limits->bound(new FilterCollection([Filter::tryFromArray(['kinds' => [1], 'limit' => 5000])]));

        $this->assertNull($limits->enforce(0, $bounded));
        $this->assertSame([100], array_map(static fn (Filter $filter): ?int => $filter->getLimit(), $bounded->toArray()));
    }

    public function testACeilingAboveFiveThousandIsTheRelaysToChoose(): void
    {
        $limits = new SubscriptionLimits(10, 5, 10_000, 100);

        $bounded = $limits->bound(new FilterCollection([Filter::tryFromArray(['kinds' => [1], 'limit' => 9_000])]));

        $this->assertSame([9_000], array_map(static fn (Filter $filter): ?int => $filter->getLimit(), $bounded->toArray()));
    }

    public function testAValueCeilingOfZeroIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionLimits(10, 5, 100, 0);
    }

    public function testAFilterHoldingNoMoreValuesThanTheCeilingIsAllowed(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100, 4);

        $this->assertNull($limits->refuseOversizedFilters(new FilterCollection([$this->filterOfFourValues()])));
    }

    public function testAFilterHoldingMoreValuesThanTheCeilingIsRefused(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100, 3);

        $rejection = $limits->refuseOversizedFilters(new FilterCollection([Filter::from(), $this->filterOfFourValues()]));

        $this->assertSame('blocked: too many values in one filter (max 3)', $rejection?->toWireReason());
    }

    public function testTheValueCeilingIsPerFilterNotPerRequest(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100, 4);

        $this->assertNull($limits->refuseOversizedFilters(new FilterCollection([$this->filterOfFourValues(), $this->filterOfFourValues()])));
    }

    public function testACeilingOfZeroIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionLimits(10, 5, 0, 100);
    }

    public function testAFilterStatingNoLimitIsGivenTheCeiling(): void
    {
        $limits = new SubscriptionLimits(10, 5, 100, 100);

        $bounded = $limits->bound(new FilterCollection([Filter::tryFromArray(['kinds' => [1]])]));

        $this->assertSame([100], array_map(static fn (Filter $filter): ?int => $filter->getLimit(), $bounded->toArray()));
    }

    private function filterOfFourValues(): Filter
    {
        return Filter::tryFromArray([
            'ids' => [str_repeat('a', 64)],
            'authors' => [str_repeat('b', 64)],
            'kinds' => [1],
            '#t' => ['nostr'],
        ]) ?? self::fail('filter did not parse');
    }
}
