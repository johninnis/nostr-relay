<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Contract;

use Closure;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;

final readonly class RelayEventStoreContractCase
{
    /**
     * @param Closure(RelayEventStoreInterface, EventCollection): (list<string>|int) $operation
     * @param list<string>|int                                                       $expected
     */
    private function __construct(
        private Closure $operation,
        private array|int $expected,
        private EventCollection $stored,
    ) {
    }

    public static function reading(FilterCollection $filters, ActingKeys $reader, EventCollection $expected): self
    {
        return new self(
            static fn (RelayEventStoreInterface $store): array => self::idsRead($store->findByFilters($filters, $reader)),
            self::idsOf($expected),
            new EventCollection([]),
        );
    }

    public static function counting(FilterCollection $filters, ActingKeys $reader, int $expected): self
    {
        return new self(
            static fn (RelayEventStoreInterface $store): int => $store->countByFilters($filters, $reader)->toInt(),
            $expected,
            new EventCollection([]),
        );
    }

    public static function deleting(EventIdCollection $eventIds, ActingKeys $deleter, EventCollection $remaining): self
    {
        return new self(
            static function (RelayEventStoreInterface $store, EventCollection $stored) use ($eventIds, $deleter): array {
                $store->deleteByEventIds($eventIds, $deleter);

                return self::stillStored($store, $stored);
            },
            self::sorted(self::idsOf($remaining)),
            new EventCollection([]),
        );
    }

    public function given(Event ...$stored): self
    {
        return new self($this->operation, $this->expected, new EventCollection(array_values($stored)));
    }

    /**
     * @return list<string>|int
     */
    public function getExpected(): array|int
    {
        return $this->expected;
    }

    /**
     * @return list<string>|int
     */
    public function observe(RelayEventStoreInterface $store): array|int
    {
        foreach ($this->stored as $event) {
            $store->store($event);
        }

        return ($this->operation)($store, $this->stored);
    }

    /**
     * @return list<string>
     */
    private static function stillStored(RelayEventStoreInterface $store, EventCollection $stored): array
    {
        return self::sorted(self::idsRead($store->findByFilters(
            new FilterCollection([Filter::from(ids: new EventIdCollection(array_map(static fn (Event $event) => $event->getId(), $stored->toArray())))]),
            self::everyKeyIn($stored),
        )));
    }

    /**
     * @return list<string>
     */
    private static function idsRead(StoredEventCollection $events): array
    {
        return array_map(static fn (StoredEvent $event): string => $event->getHeader()->getId()->toHex(), $events->toArray());
    }

    /**
     * @return list<string>
     */
    private static function idsOf(EventCollection $events): array
    {
        return array_map(static fn (Event $event): string => $event->getId()->toHex(), $events->toArray());
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private static function everyKeyIn(EventCollection $events): ActingKeys
    {
        return new ActingKeys(new PublicKeyCollection(array_merge(...array_map(
            static fn (Event $event): array => [$event->getPubkey(), ...$event->getTags()->getPubkeys()->toArray()],
            $events->toArray(),
        ))));
    }
}
