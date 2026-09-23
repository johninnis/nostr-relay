<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;

final readonly class ScopedFilters
{
    private function __construct(
        private FilterCollection $requested,
        private FilterCollection $filters,
        private bool $beyondScope,
    ) {
    }

    public static function unchanged(FilterCollection $filters): self
    {
        return new self($filters, $filters, false);
    }

    public static function scoped(FilterCollection $requested, FilterCollection $filters, bool $beyondScope): self
    {
        return new self($requested, $filters, $beyondScope);
    }

    // Deliberate: the filters the client asked for travel with the ones it was granted, because a re-evaluation can only widen from the original and the granted set is a lossy projection — see ADR-0016
    public function getRequestedFilters(): FilterCollection
    {
        return $this->requested;
    }

    public function getFilters(): FilterCollection
    {
        return $this->filters;
    }

    public function isBeyondScope(): bool
    {
        return $this->beyondScope;
    }
}
