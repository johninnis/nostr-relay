<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use PHPUnit\Framework\TestCase;

final class ScopedFiltersTest extends TestCase
{
    public function testUnchangedIsNotBeyondScope(): void
    {
        $filters = new FilterCollection([Filter::from()]);

        $scoped = ScopedFilters::unchanged($filters);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertFalse($scoped->isBeyondScope());
    }

    public function testScopedCarriesFiltersAndBeyondScopeFlag(): void
    {
        $filters = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $scoped = ScopedFilters::scoped($filters, $filters, true);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertTrue($scoped->isBeyondScope());
    }

    public function testTheRequestedFiltersSurviveScopingAndAreNotTheGrantedOnes(): void
    {
        $requested = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1, 4]))]);
        $granted = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([1]))]);

        $scoped = ScopedFilters::scoped($requested, $granted, true);

        $this->assertSame([1, 4], $scoped->getRequestedFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testScopedCanDropAllFiltersWhileStillCarryingWhatWasAsked(): void
    {
        $requested = new FilterCollection([Filter::from(kinds: EventKindCollection::fromInts([4]))]);

        $scoped = ScopedFilters::scoped($requested, new FilterCollection(), true);

        $this->assertCount(1, $scoped->getRequestedFilters());
    }

    public function testScopedCanDropAllFiltersWhileFlaggingBeyondScope(): void
    {
        $filters = new FilterCollection();

        $scoped = ScopedFilters::scoped($filters, $filters, true);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertTrue($scoped->isBeyondScope());
    }
}
