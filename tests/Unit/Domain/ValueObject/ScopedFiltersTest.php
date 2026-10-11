<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\Collection\FilterGroupCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use PHPUnit\Framework\TestCase;

final class ScopedFiltersTest extends TestCase
{
    public function testUnchangedCarriesTheFilters(): void
    {
        $filters = new FilterCollection([Filter::from()]);

        $this->assertSame($filters, ScopedFilters::unchanged($filters)->getFilters());
    }

    public function testUnchangedIsNotBeyondScope(): void
    {
        $this->assertFalse(ScopedFilters::unchanged(new FilterCollection([Filter::from()]))->isBeyondScope());
    }

    public function testScopedCarriesTheGrantedFilters(): void
    {
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $this->assertSame($filters, ScopedFilters::scoped($filters, true)->getFilters());
    }

    public function testScopedCarriesTheBeyondScopeFlag(): void
    {
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $this->assertTrue(ScopedFilters::scoped($filters, true)->isBeyondScope());
    }

    public function testScopedCanDropAllFilters(): void
    {
        $filters = new FilterCollection();

        $this->assertSame($filters, ScopedFilters::scoped($filters, true)->getFilters());
    }

    public function testScopedCanDropAllFiltersWhileFlaggingBeyondScope(): void
    {
        $filters = new FilterCollection();

        $this->assertTrue(ScopedFilters::scoped($filters, true)->isBeyondScope());
    }

    public function testAReadBeyondScopeWhoseGrantedFiltersCanMatchNothingIsWhollyBeyondScope(): void
    {
        $granted = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([]))]);

        $this->assertTrue(ScopedFilters::scoped($granted, true)->isWhollyBeyondScope());
    }

    public function testAReadBeyondScopeLeftWithNoFiltersIsWhollyBeyondScope(): void
    {
        $this->assertTrue(ScopedFilters::scoped(new FilterCollection(), true)->isWhollyBeyondScope());
    }

    public function testAReadBeyondScopeThatCanStillMatchIsNotWhollyBeyondScope(): void
    {
        $granted = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([])), Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $this->assertFalse(ScopedFilters::scoped($granted, true)->isWhollyBeyondScope());
    }

    public function testAReadWithinScopeThatCanMatchNothingIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(ScopedFilters::unchanged(new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([]))]))->isWhollyBeyondScope());
    }

    public function testUnchangedFiltersAreEachReadOnTheirOwn(): void
    {
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1])), Filter::from(kinds: EventKindCollection::fromInts([7]))]);

        $this->assertCount(2, ScopedFilters::unchanged($filters)->getGroups());
    }

    public function testScopedFiltersAreEachReadOnTheirOwn(): void
    {
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1])), Filter::from(kinds: EventKindCollection::fromInts([7]))]);

        $this->assertCount(2, ScopedFilters::scoped($filters, true)->getGroups());
    }

    public function testGroupedFiltersAreReadTogetherWithinTheirGroup(): void
    {
        $pair = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1])), Filter::from(kinds: EventKindCollection::fromInts([1059]))]);

        $this->assertSame($pair, ScopedFilters::grouped(new FilterGroupCollection([$pair]), true)->getGroups()->toArray()[0]);
    }

    public function testGroupedFiltersAreGrantedAsOneCollection(): void
    {
        $first = Filter::from(kinds: EventKindCollection::fromInts([1]));
        $second = Filter::from(kinds: EventKindCollection::fromInts([1059]));
        $third = Filter::from(kinds: EventKindCollection::fromInts([7]));

        $scoped = ScopedFilters::grouped(new FilterGroupCollection([new FilterCollection([$first, $second]), new FilterCollection([$third])]), false);

        $this->assertSame([$first, $second, $third], $scoped->getFilters()->toArray());
    }
}
