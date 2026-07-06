<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class GuestFilterRules
{
    private EventKindCollection $readableKindsWithGlobal;

    public function __construct(
        private PublicKeyCollection $tenants,
        private EventKindCollection $readableKinds,
        private EventKindCollection $globalKinds,
    ) {
        $this->readableKindsWithGlobal = $readableKinds->isEmpty()
            ? $readableKinds
            : EventKindCollection::fromInts([...$readableKinds->toInts(), ...$globalKinds->toInts()]);
    }

    public function scope(FilterCollection $filters, bool $fromTenantsOnly): ScopedFilters
    {
        $beyondScope = false;
        $scoped = [];

        foreach ($filters as $filter) {
            $tenantScoped = $fromTenantsOnly && !$this->isGlobalOnly($filter);
            $beyondScope = $beyondScope || $this->isBeyondScope($filter, $tenantScoped);
            $scoped[] = $this->constrain($filter, $tenantScoped);
        }

        return ScopedFilters::scoped(new FilterCollection($scoped), $beyondScope);
    }

    public function allowsEvent(Event $event, bool $fromTenantsOnly): bool
    {
        if (!$this->isReadableKind($event->getKind())) {
            return false;
        }

        if ($this->globalKinds->contains($event->getKind())) {
            return true;
        }

        if ($fromTenantsOnly) {
            return $this->tenants->contains($event->getPubkey());
        }

        return true;
    }

    private function isGlobalOnly(Filter $filter): bool
    {
        if ($this->globalKinds->isEmpty()) {
            return false;
        }

        $kinds = $filter->getKinds();

        if (null === $kinds || $kinds->isEmpty()) {
            return false;
        }

        return $kinds->diff($this->globalKinds)->isEmpty();
    }

    private function isReadableKind(EventKind $kind): bool
    {
        return $this->readableKindsWithGlobal->isEmpty() || $this->readableKindsWithGlobal->contains($kind);
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
        $taggedPubkeys = PublicKeyCollection::fromHexValues($filter->getTags()?->getValues()[TagType::PUBKEY] ?? []);

        return !$taggedPubkeys->intersect($this->tenants)->isEmpty();
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
            return $filter->withAuthors($this->tenants);
        }

        return $filter->withAuthors($authors->intersect($this->tenants));
    }

    private function constrainKindsToReadable(Filter $filter): Filter
    {
        if ($this->readableKindsWithGlobal->isEmpty()) {
            return $filter;
        }

        $kinds = $filter->getKinds();

        if (null === $kinds) {
            return $filter->withKinds($this->readableKindsWithGlobal);
        }

        return $filter->withKinds($kinds->intersect($this->readableKindsWithGlobal));
    }

    private function authorsWithinTenants(Filter $filter): bool
    {
        $authors = $filter->getAuthors();

        return null === $authors || $authors->diff($this->tenants)->isEmpty();
    }

    private function kindsWithinReadable(Filter $filter): bool
    {
        if ($this->readableKindsWithGlobal->isEmpty()) {
            return true;
        }

        $kinds = $filter->getKinds();

        return null === $kinds || $kinds->diff($this->readableKindsWithGlobal)->isEmpty();
    }
}
