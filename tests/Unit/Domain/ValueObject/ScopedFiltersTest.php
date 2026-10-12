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
}
