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
        $filters = new FilterCollection([new Filter()]);

        $scoped = ScopedFilters::unchanged($filters);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertFalse($scoped->isBeyondScope());
    }

    public function testScopedCarriesFiltersAndBeyondScopeFlag(): void
    {
        $filters = new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1]))]);

        $scoped = ScopedFilters::scoped($filters, true);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertTrue($scoped->isBeyondScope());
    }

    public function testScopedCanDropAllFiltersWhileFlaggingBeyondScope(): void
    {
        $filters = new FilterCollection();

        $scoped = ScopedFilters::scoped($filters, true);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertTrue($scoped->isBeyondScope());
    }
}
