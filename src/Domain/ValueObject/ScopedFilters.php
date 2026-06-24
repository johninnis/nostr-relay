<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;

final readonly class ScopedFilters
{
    private function __construct(
        private FilterCollection $filters,
        private bool $beyondScope,
    ) {
    }

    public static function unchanged(FilterCollection $filters): self
    {
        return new self($filters, false);
    }

    public static function scoped(FilterCollection $filters, bool $beyondScope): self
    {
        return new self($filters, $beyondScope);
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
