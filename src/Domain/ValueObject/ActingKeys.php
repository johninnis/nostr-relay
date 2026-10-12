<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;

// Deliberate: the one definition of the gift-wrap rule, carried to the store with every read so it applies the rule on every filter before its limit — see ADR-0036
final readonly class ActingKeys
{
    private const array CONFINED_KINDS = [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP];

    public function __construct(
        private PublicKeyCollection $keys,
    ) {
    }

    public function getKeys(): PublicKeyCollection
    {
        return $this->keys;
    }

    public function getConfinedKinds(): EventKindCollection
    {
        return EventKindCollection::fromInts(self::CONFINED_KINDS);
    }

    public function canRead(Event $event): bool
    {
        return !$this->getConfinedKinds()->contains($event->getKind()) || $this->isAddressedTo($event);
    }

    // Deliberate: a key deletes what it signed and the gift wraps addressed to it, and never a deletion — see ADR-0038
    public function canDelete(Event $event): bool
    {
        return !$event->getKind()->is(EventKind::EVENT_DELETION)
            && ($this->keys->contains($event->getPubkey()) || ($this->getConfinedKinds()->contains($event->getKind()) && $this->isAddressedTo($event)));
    }

    // Deliberate: a filter naming a gift-wrap kind beyond these keys asks for what authenticating as another key could unlock; a filter naming no kind asks for no gift wrap — see ADR-0036
    public function narrows(Filter $filter): bool
    {
        if (null === $filter->getKinds() || $filter->getKinds()->intersect($this->getConfinedKinds())->isEmpty()) {
            return false;
        }

        $recipients = self::recipientsOf($filter);

        return null === $recipients || !$recipients->diff($this->keys)->isEmpty();
    }

    public function leavesMatchable(Filter $filter): bool
    {
        if (!$filter->canMatch()) {
            return false;
        }

        if (null === $filter->getKinds() || !$filter->getKinds()->diff($this->getConfinedKinds())->isEmpty()) {
            return true;
        }

        return !(self::recipientsOf($filter) ?? $this->keys)->intersect($this->keys)->isEmpty();
    }

    private function isAddressedTo(Event $event): bool
    {
        return !$event->getTags()->getPubkeys()->intersect($this->keys)->isEmpty();
    }

    private static function recipientsOf(Filter $filter): ?PublicKeyCollection
    {
        $values = $filter->getTags()?->getValues()[TagType::PUBKEY] ?? null;

        return null === $values ? null : PublicKeyCollection::fromHexValues($values);
    }
}
