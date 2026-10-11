<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagFilter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Domain\Collection\FilterGroupCollection;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class GiftWrapFilterRules
{
    private const array GIFT_WRAP_KINDS = [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP];

    // Deliberate: a filter naming a gift-wrap kind is split, so its other kinds stay readable while its gift wraps are confined to the reader's own keys, and the split halves stay one group that answers within the filter's one limit — see ADR-0035
    public function confine(ScopedFilters $scoped, PublicKeyCollection $readers): ScopedFilters
    {
        $groups = array_map(
            fn (FilterCollection $group): FilterCollection => new FilterCollection(array_merge(...array_map(
                fn (Filter $filter): array => $this->confineFilter($filter, $readers),
                $group->toArray(),
            ))),
            $scoped->getGroups()->toArray(),
        );

        return ScopedFilters::grouped(
            new FilterGroupCollection(array_values(array_filter($groups, static fn (FilterCollection $group): bool => !$group->isEmpty()))),
            $scoped->isBeyondScope() || array_any($scoped->getFilters()->toArray(), fn (Filter $filter): bool => $this->isNarrowedFor($filter, $readers)),
        );
    }

    // Deliberate: a filter naming no kind cannot exclude the gift-wrap kinds, so it matches no gift wrap, and a wrap reaches a subscription only through a filter naming its kind — see ADR-0035
    public function matches(FilterCollection $filters, Event $event): bool
    {
        $isGiftWrap = $this->giftWrapKinds()->contains($event->getKind());

        return array_any(
            $filters->toArray(),
            static fn (Filter $filter): bool => (!$isGiftWrap || null !== $filter->getKinds()) && $filter->matches($event),
        );
    }

    // Deliberate: judged by the header's kind, never by the event's p tag, so a stored read delivers without a parse — see ADR-0035
    public function withholds(FilterCollection $filters, EventHeader $header): bool
    {
        return $this->giftWrapKinds()->contains($header->getKind()) && $this->withholdsGiftWraps($filters);
    }

    public function withholdsGiftWraps(FilterCollection $filters): bool
    {
        return array_any($filters->toArray(), static fn (Filter $filter): bool => null === $filter->getKinds());
    }

    public function giftWrapsMatchedBy(FilterCollection $filters): FilterCollection
    {
        return new FilterCollection(array_values(array_filter(array_map(
            fn (Filter $filter): ?Filter => $this->giftWrapPartOf($filter),
            $filters->toArray(),
        ))));
    }

    public function giftWrapsNamedBy(FilterCollection $filters): FilterCollection
    {
        return $this->giftWrapsMatchedBy(new FilterCollection(array_values(array_filter(
            $filters->toArray(),
            static fn (Filter $filter): bool => null !== $filter->getKinds(),
        ))));
    }

    /**
     * @return list<Filter>
     */
    private function confineFilter(Filter $filter, PublicKeyCollection $readers): array
    {
        $giftWraps = $this->giftWrapPartOf($filter);
        $kinds = $filter->getKinds();

        if (null === $giftWraps || null === $kinds) {
            return [$filter];
        }

        $recipients = $this->recipientsOf($filter)?->intersect($readers) ?? $readers;
        $otherKinds = $kinds->diff($this->giftWrapKinds());

        return [
            ...($otherKinds->isEmpty() ? [] : [$filter->withKinds($otherKinds)]),
            ...($recipients->isEmpty() ? [] : [$this->addressedTo($giftWraps, $recipients)]),
        ];
    }

    private function isNarrowedFor(Filter $filter, PublicKeyCollection $readers): bool
    {
        if (null === $filter->getKinds() || null === $this->giftWrapPartOf($filter)) {
            return false;
        }

        $recipients = $this->recipientsOf($filter);

        return null === $recipients || !$recipients->diff($readers)->isEmpty();
    }

    private function giftWrapPartOf(Filter $filter): ?Filter
    {
        $giftWrapKinds = $filter->getKinds()?->intersect($this->giftWrapKinds()) ?? $this->giftWrapKinds();

        return $giftWrapKinds->isEmpty() ? null : $filter->withKinds($giftWrapKinds);
    }

    private function recipientsOf(Filter $filter): ?PublicKeyCollection
    {
        $values = $filter->getTags()?->getValues()[TagType::PUBKEY] ?? null;

        return null === $values ? null : PublicKeyCollection::fromHexValues($values);
    }

    private function addressedTo(Filter $filter, PublicKeyCollection $recipients): Filter
    {
        return $filter->withTags(TagFilter::fromValues([
            ...$filter->getTags()?->getValues() ?? [],
            TagType::PUBKEY => $recipients->toHexes(),
        ]));
    }

    private function giftWrapKinds(): EventKindCollection
    {
        return EventKindCollection::fromInts(self::GIFT_WRAP_KINDS);
    }
}
