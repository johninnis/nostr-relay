<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use InvalidArgumentException;

final readonly class SubscriptionLimits
{
    public const int MIN_CEILING = 1;

    public function __construct(
        private int $maxSubscriptions,
        private int $maxFilters,
        private int $maxQueryLimit,
        private int $maxFilterValues,
    ) {
        foreach (['query limit' => $maxQueryLimit, 'filter value limit' => $maxFilterValues] as $name => $ceiling) {
            if (!self::isCeilingInRange($ceiling)) {
                throw new InvalidArgumentException('A '.$name.' of '.$ceiling.' is below the '.self::MIN_CEILING.' this relay needs to serve anything');
            }
        }
    }

    // Deliberate: a ceiling of zero is refused although a filter accepts a limit of zero, because a relay that answers every read with nothing is a misconfiguration and not a policy — see ADR-0027
    public static function isCeilingInRange(int $ceiling): bool
    {
        return $ceiling >= self::MIN_CEILING;
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

    // Deliberate: counted across every list field of one filter, because that is what one store query binds — see ADR-0024
    public function refuseOversizedFilters(FilterCollection $filters): ?PolicyRejection
    {
        return array_any($filters->toArray(), fn (Filter $filter): bool => self::valueCount($filter) > $this->maxFilterValues)
            ? PolicyRejection::blocked('too many values in one filter (max '.$this->maxFilterValues.')')
            : null;
    }

    // Deliberate: clamped, never refused — NIP-11 says a relay clamps each filter's limit to the number it advertises as max_limit, and a filter stating none is asking for the ceiling — see ADR-0024
    public function bound(FilterCollection $filters): FilterCollection
    {
        return new FilterCollection(array_map(
            fn (Filter $filter): Filter => $filter->withLimit(min($filter->getLimit() ?? $this->maxQueryLimit, $this->maxQueryLimit)),
            $filters->toArray(),
        ));
    }

    private static function valueCount(Filter $filter): int
    {
        return count($filter->getIds() ?? [])
            + count($filter->getAuthors() ?? [])
            + count($filter->getKinds() ?? [])
            + array_sum(array_map(count(...), $filter->getTags()?->getValues() ?? []));
    }
}
