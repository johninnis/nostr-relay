<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\Filter;

final readonly class GuestFilterRules
{
    public function __construct(
        private array $tenantHexKeys,
        private array $readableKinds,
    ) {
    }

    public function constrainAuthorsToTenants(Filter $filter): Filter
    {
        if ($filter->hasAuthors()) {
            $requested = array_map(strtolower(...), $filter->getAuthors() ?? []);

            return $filter->withAuthors(array_values(array_intersect($requested, $this->tenantHexKeys)));
        }

        return $filter->withAuthors($this->tenantHexKeys);
    }

    public function injectReadableKinds(Filter $filter): Filter
    {
        if (!$filter->hasKinds() && [] !== $this->readableKinds) {
            return $filter->withKinds($this->readableKinds);
        }

        return $filter;
    }

    public function authorsWithinTenants(Filter $filter): bool
    {
        if (!$filter->hasAuthors()) {
            return true;
        }

        $requested = array_map(strtolower(...), $filter->getAuthors() ?? []);

        return [] === array_diff($requested, $this->tenantHexKeys);
    }

    public function kindsWithinReadable(Filter $filter): bool
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
