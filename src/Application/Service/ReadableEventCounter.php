<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;

final readonly class ReadableEventCounter
{
    public function __construct(
        private RelayEventStoreInterface $eventStore,
        private GiftWrapFilterRules $giftWrapRules,
    ) {
    }

    // Deliberate: a count has no delivery step to drop gift wraps at, so the wraps only a filter naming no kind would count are taken out by counting filter unions: all, less the gift wraps any filter matches, plus those a filter naming their kind matches — see ADR-0035
    public function count(FilterCollection $filters): EventCount
    {
        $total = $this->eventStore->countByFilters($filters);

        if (!$this->giftWrapRules->withholdsGiftWraps($filters)) {
            return $total;
        }

        $giftWraps = $this->eventStore->countByFilters($this->giftWrapRules->giftWrapsMatchedBy($filters));
        $namedGiftWraps = $this->giftWrapRules->giftWrapsNamedBy($filters);
        $readableGiftWraps = $namedGiftWraps->isEmpty() ? EventCount::exact(0) : $this->eventStore->countByFilters($namedGiftWraps);
        $readable = max(0, $total->toInt() - $giftWraps->toInt() + $readableGiftWraps->toInt());

        return $total->isApproximate() || $giftWraps->isApproximate() || $readableGiftWraps->isApproximate()
            ? EventCount::approximate($readable)
            : EventCount::exact($readable);
    }
}
