<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class ScopedFilters
{
    private function __construct(
        private array $filters,
        private bool $beyondScope,
    ) {
    }

    public static function unchanged(array $filters): self
    {
        return new self($filters, false);
    }

    public static function scoped(array $filters, bool $beyondScope): self
    {
        return new self($filters, $beyondScope);
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function isBeyondScope(): bool
    {
        return $this->beyondScope;
    }
}
