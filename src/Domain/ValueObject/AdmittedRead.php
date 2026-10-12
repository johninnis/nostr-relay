<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;

final readonly class AdmittedRead
{
    public function __construct(
        private ScopedFilters $scoped,
        private ActingKeys $reader,
    ) {
    }

    public function getFilters(): FilterCollection
    {
        return $this->scoped->getFilters();
    }

    public function getReader(): ActingKeys
    {
        return $this->reader;
    }

    // Deliberate: a read is beyond scope when the policy narrowed it or when it asks for gift wraps beyond the reader's keys, so either draws the lazy challenge — see ADR-0004 and ADR-0036
    public function isBeyondScope(): bool
    {
        return $this->scoped->isBeyondScope() || array_any($this->getFilters()->toArray(), $this->reader->narrows(...));
    }

    public function isWhollyBeyondScope(): bool
    {
        return $this->isBeyondScope() && !array_any($this->getFilters()->toArray(), $this->reader->leavesMatchable(...));
    }
}
