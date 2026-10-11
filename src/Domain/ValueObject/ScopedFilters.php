<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\Collection\FilterGroupCollection;

final readonly class ScopedFilters
{
    private function __construct(
        private FilterCollection $filters,
        private FilterGroupCollection $groups,
        private bool $beyondScope,
    ) {
    }

    public static function unchanged(FilterCollection $filters): self
    {
        return self::scoped($filters, false);
    }

    public static function scoped(FilterCollection $filters, bool $beyondScope): self
    {
        return new self(
            $filters,
            new FilterGroupCollection(array_map(static fn (Filter $filter): FilterCollection => new FilterCollection([$filter]), $filters->toArray())),
            $beyondScope,
        );
    }

    // Deliberate: each group is the filters granted for one filter the client asked for, read together so they answer within that filter's one limit — see ADR-0035
    public static function grouped(FilterGroupCollection $groups, bool $beyondScope): self
    {
        return new self(
            new FilterCollection(array_merge(...array_map(static fn (FilterCollection $group): array => $group->toArray(), $groups->toArray()))),
            $groups,
            $beyondScope,
        );
    }

    public function getFilters(): FilterCollection
    {
        return $this->filters;
    }

    public function getGroups(): FilterGroupCollection
    {
        return $this->groups;
    }

    public function isBeyondScope(): bool
    {
        return $this->beyondScope;
    }

    public function isWhollyBeyondScope(): bool
    {
        return $this->beyondScope && $this->filters->matchable()->isEmpty();
    }
}
