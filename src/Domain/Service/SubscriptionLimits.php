<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use InvalidArgumentException;

final readonly class SubscriptionLimits
{
    public const int MIN_QUERY_LIMIT = 1;

    public function __construct(
        private int $maxSubscriptions,
        private int $maxFilters,
        private int $maxQueryLimit,
    ) {
        if (!self::isQueryLimitInRange($maxQueryLimit)) {
            throw new InvalidArgumentException('A query limit of '.$maxQueryLimit.' is outside the range this relay can apply, '.self::MIN_QUERY_LIMIT.' to '.Filter::MAX_LIMIT);
        }
    }

    // Deliberate: a ceiling of zero is refused although a filter accepts it, because a relay that answers every read with nothing is a misconfiguration and not a policy — see ADR-0019
    public static function isQueryLimitInRange(int $limit): bool
    {
        return $limit >= self::MIN_QUERY_LIMIT && $limit <= Filter::MAX_LIMIT;
    }

    public function enforce(int $currentSubscriptionCount, FilterCollection $filters): ?PolicyRejection
    {
        if ($currentSubscriptionCount >= $this->maxSubscriptions) {
            return PolicyRejection::blocked('too many subscriptions (max '.$this->maxSubscriptions.')');
        }

        if (count($filters) > $this->maxFilters) {
            return PolicyRejection::blocked('too many filters (max '.$this->maxFilters.')');
        }

        return null;
    }

    // Deliberate: clamped, never refused — NIP-11 says a relay clamps each filter's limit to the number it advertises as max_limit, and a filter stating none is asking for the ceiling — see ADR-0017
    public function bound(FilterCollection $filters): FilterCollection
    {
        return new FilterCollection(array_map(
            fn (Filter $filter): Filter => $filter->withLimit(min($filter->getLimit() ?? $this->maxQueryLimit, $this->maxQueryLimit)),
            $filters->toArray(),
        ));
    }
}
