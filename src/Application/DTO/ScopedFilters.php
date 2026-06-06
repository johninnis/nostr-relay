<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\DTO;

use Innis\Nostr\Core\Domain\Entity\Filter;

final readonly class ScopedFilters
{
    private function __construct(
        private array $filters,
        private bool $narrowed,
    ) {
    }

    public static function unchanged(array $filters): self
    {
        return new self($filters, false);
    }

    public static function fromMapping(array $original, array $scoped): self
    {
        return new self($scoped, self::narrowed($original, $scoped));
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function wasNarrowed(): bool
    {
        return $this->narrowed;
    }

    private static function narrowed(array $original, array $scoped): bool
    {
        $canonical = static fn (Filter $filter): array => $filter->toArray();

        return array_map($canonical, $original) !== array_map($canonical, $scoped);
    }
}
