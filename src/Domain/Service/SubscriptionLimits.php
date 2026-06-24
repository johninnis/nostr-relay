<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;

final readonly class SubscriptionLimits
{
    public function __construct(
        private int $maxSubscriptions,
        private int $maxFilters,
        private int $maxQueryLimit,
    ) {
    }

    public function enforce(int $currentSubscriptionCount, FilterCollection $filters): void
    {
        if ($currentSubscriptionCount >= $this->maxSubscriptions) {
            throw new PolicyViolationException('too many subscriptions (max '.$this->maxSubscriptions.')');
        }

        if (count($filters) > $this->maxFilters) {
            throw new PolicyViolationException('too many filters (max '.$this->maxFilters.')');
        }

        foreach ($filters as $filter) {
            if ($filter->hasLimit() && $filter->getLimit() > $this->maxQueryLimit) {
                throw new PolicyViolationException('filter limit too high (max '.$this->maxQueryLimit.')');
            }
        }
    }
}
