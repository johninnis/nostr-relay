<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\DTO;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Relay\Application\DTO\ScopedFilters;
use PHPUnit\Framework\TestCase;

final class ScopedFiltersTest extends TestCase
{
    public function testUnchangedReportsNoNarrowing(): void
    {
        $filters = [new Filter()];

        $scoped = ScopedFilters::unchanged($filters);

        $this->assertSame($filters, $scoped->getFilters());
        $this->assertFalse($scoped->wasNarrowed());
    }

    public function testFromMappingDetectsNarrowing(): void
    {
        $original = [Filter::fromArray([])];
        $narrowed = [Filter::fromArray(['kinds' => [1]])];

        $scoped = ScopedFilters::fromMapping($original, $narrowed);

        $this->assertSame($narrowed, $scoped->getFilters());
        $this->assertTrue($scoped->wasNarrowed());
    }

    public function testFromMappingReportsNoNarrowingWhenCanonicallyIdentical(): void
    {
        $original = [Filter::fromArray(['kinds' => [1]])];
        $scoped = [Filter::fromArray(['kinds' => [1]])];

        $result = ScopedFilters::fromMapping($original, $scoped);

        $this->assertFalse($result->wasNarrowed());
    }
}
