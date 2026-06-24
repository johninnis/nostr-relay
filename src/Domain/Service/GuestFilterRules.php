<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class GuestFilterRules
{
    public function __construct(
        private array $tenantHexKeys,
        private array $readableKinds,
    ) {
    }

    public function scope(FilterCollection $filters, bool $fromTenantsOnly): ScopedFilters
    {
        $beyondScope = false;
        $scoped = [];

        foreach ($filters as $filter) {
            $beyondScope = $beyondScope || $this->isBeyondScope($filter, $fromTenantsOnly);
            $scoped[] = $this->constrain($filter, $fromTenantsOnly);
        }

        return ScopedFilters::scoped(new FilterCollection($scoped), $beyondScope);
    }

    public function allowsEvent(Event $event, bool $fromTenantsOnly): bool
    {
        if ([] !== $this->readableKinds && !in_array($event->getKind()->toInt(), $this->readableKinds, true)) {
            return false;
        }

        if ($fromTenantsOnly) {
            return in_array($event->getPubkey()->toHex(), $this->tenantHexKeys, true);
        }

        return true;
    }

    private function isBeyondScope(Filter $filter, bool $fromTenantsOnly): bool
    {
        if ($fromTenantsOnly && $this->referencesTenantInPTag($filter)) {
            return true;
        }

        if ($fromTenantsOnly && !$this->authorsWithinTenants($filter)) {
            return true;
        }

        return !$this->kindsWithinReadable($filter);
    }

    private function referencesTenantInPTag(Filter $filter): bool
    {
        $pubkeys = $filter->getTags()['p'] ?? null;

        if (!is_array($pubkeys)) {
            return false;
        }

        foreach ($pubkeys as $pubkey) {
            if (is_string($pubkey) && in_array(strtolower($pubkey), $this->tenantHexKeys, true)) {
                return true;
            }
        }

        return false;
    }

    private function constrain(Filter $filter, bool $fromTenantsOnly): Filter
    {
        $constrained = $fromTenantsOnly ? $this->constrainAuthorsToTenants($filter) : $filter;

        return $this->constrainKindsToReadable($constrained);
    }

    private function constrainAuthorsToTenants(Filter $filter): Filter
    {
        if ($filter->hasAuthors()) {
            $requested = array_map(strtolower(...), $filter->getAuthors() ?? []);

            return $filter->withAuthors(array_values(array_intersect($requested, $this->tenantHexKeys)));
        }

        return $filter->withAuthors($this->tenantHexKeys);
    }

    private function constrainKindsToReadable(Filter $filter): Filter
    {
        if ([] === $this->readableKinds) {
            return $filter;
        }

        if (!$filter->hasKinds()) {
            return $filter->withKinds($this->readableKinds);
        }

        $requested = array_map(static fn (EventKind $kind): int => $kind->toInt(), $filter->getKinds() ?? []);

        return $filter->withKinds(array_values(array_intersect($requested, $this->readableKinds)));
    }

    private function authorsWithinTenants(Filter $filter): bool
    {
        if (!$filter->hasAuthors()) {
            return true;
        }

        $requested = array_map(strtolower(...), $filter->getAuthors() ?? []);

        return [] === array_diff($requested, $this->tenantHexKeys);
    }

    private function kindsWithinReadable(Filter $filter): bool
    {
        if ([] === $this->readableKinds || !$filter->hasKinds()) {
            return true;
        }

        foreach ($filter->getKinds() ?? [] as $kind) {
            if (!in_array($kind->toInt(), $this->readableKinds, true)) {
                return false;
            }
        }

        return true;
    }
}
