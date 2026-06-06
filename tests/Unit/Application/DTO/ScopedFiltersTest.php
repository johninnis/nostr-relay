<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\DTO;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Relay\Application\DTO\ScopedFilters;
use PHPUnit\Framework\TestCase;

final class ScopedFiltersTest extends TestCase
{
    public function testUnchangedIsNotBeyondScope(): void
    {
        $filters = [new Filter()];

        $scoped = ScopedFilters::unchanged($filters);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertFalse($scoped->isBeyondScope());
    }

    public function testScopedCarriesFiltersAndBeyondScopeFlag(): void
    {
        $filters = [new Filter(kinds: [1])];

        $scoped = ScopedFilters::scoped($filters, true);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertTrue($scoped->isBeyondScope());
    }

    public function testScopedCanDropAllFiltersWhileFlaggingBeyondScope(): void
    {
        $scoped = ScopedFilters::scoped([], true);

        $this->assertSame([], $scoped->getFilters());
        $this->assertTrue($scoped->isBeyondScope());
    }
}
