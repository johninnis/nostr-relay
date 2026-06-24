<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class GuestFilterRules
{
    public function __construct(
        private PublicKeyCollection $tenants,
        private EventKindCollection $readableKinds,
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
        if (!$this->readableKinds->isEmpty() && !$this->readableKinds->contains($event->getKind())) {
            return false;
        }

        if ($fromTenantsOnly) {
            return $this->tenants->contains($event->getPubkey());
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
            $candidate = PublicKey::fromHex(strtolower($pubkey));
            if (null !== $candidate && $this->tenants->contains($candidate)) {
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
        $authors = $filter->getAuthors();

        if (null === $authors) {
            return $filter->withAuthors($this->tenants->toHexes());
        }

        return $filter->withAuthors($authors->intersect($this->tenants)->toHexes());
    }

    private function constrainKindsToReadable(Filter $filter): Filter
    {
        if ($this->readableKinds->isEmpty()) {
            return $filter;
        }

        $kinds = $filter->getKinds();

        if (null === $kinds) {
            return $filter->withKinds($this->readableKinds->toInts());
        }

        return $filter->withKinds($kinds->intersect($this->readableKinds)->toInts());
    }

    private function authorsWithinTenants(Filter $filter): bool
    {
        $authors = $filter->getAuthors();

        return null === $authors || $authors->diff($this->tenants)->isEmpty();
    }

    private function kindsWithinReadable(Filter $filter): bool
    {
        if ($this->readableKinds->isEmpty()) {
            return true;
        }

        $kinds = $filter->getKinds();

        return null === $kinds || $kinds->diff($this->readableKinds)->isEmpty();
    }
}
