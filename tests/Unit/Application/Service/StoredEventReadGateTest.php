<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Closure;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Domain\Collection\FilterGroupCollection;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoredEventReadGateTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAReadableEventIsReturned(): void
    {
        $stored = $this->storedEvent(null);

        $readable = $this->readGate(new StoredEventCollection([$stored]), self::NOW)->readableFor($this->client(), ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([$stored], $readable->toArray());
    }

    public function testAnEventExpiredAtNowIsWithheld(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(self::NOW)]), self::NOW)
            ->readableFor($this->client(), ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([], $readable->toArray());
    }

    public function testAnEventThePolicyRefusesIsWithheld(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(null)]), self::NOW, receives: false)
            ->readableFor($this->client(), ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([], $readable->toArray());
    }

    public function testExpiryIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(self::NOW + 1)]), self::NOW - 3600)
            ->readableFor($this->client(), ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertCount(1, $readable);
    }

    public function testAStoredGiftWrapIsWithheldFromARequestNamingNoKind(): void
    {
        $readable = $this->readGate(new StoredEventCollection([StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()))]), self::NOW)
            ->readableFor($this->client(), ScopedFilters::unchanged(new FilterCollection([Filter::from()])));

        $this->assertSame([], $readable->toArray());
    }

    public function testAStoredGiftWrapIsWithheldFromARequestNamingItsRecipientWithoutAKind(): void
    {
        $readable = $this->readGate(new StoredEventCollection([StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()))]), self::NOW)
            ->readableFor($this->client(), self::scoped(['#p' => [KeyMother::bobPublicKey()->toHex()]]));

        $this->assertSame([], $readable->toArray());
    }

    public function testAStoredGiftWrapReachesARequestConfinedToItsRecipient(): void
    {
        $giftWrap = StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()));

        $readable = $this->readGate(new StoredEventCollection([$giftWrap]), self::NOW)
            ->readableFor($this->client(), self::scoped(['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]));

        $this->assertSame([$giftWrap], $readable->toArray());
    }

    public function testAGroupOfSplitFiltersAnswersWithinItsOneLimit(): void
    {
        $stored = [$this->noteAt(self::NOW - 1), $this->noteAt(self::NOW - 2), $this->noteAt(self::NOW - 3)];
        $group = new FilterCollection([self::filter(['kinds' => [1], 'limit' => 2]), self::filter(['kinds' => [EventKind::GIFT_WRAP], 'limit' => 2])]);

        $readable = $this->readGate(new StoredEventCollection($stored), self::NOW)
            ->readableFor($this->client(), ScopedFilters::grouped(new FilterGroupCollection([$group]), false));

        $this->assertSame([$stored[0], $stored[1]], $readable->toArray());
    }

    public function testEventsReadByDifferentGroupsAreListedNewestFirst(): void
    {
        $older = $this->noteAt(self::NOW - 20);
        $newer = $this->noteAt(self::NOW - 10);
        $readGate = $this->readGateAnswering(static fn (FilterCollection $filters): array => 7 === $filters->toArray()[0]->getKinds()?->toInts()[0] ? [$newer] : [$older]);

        $readable = $readGate->readableFor($this->client(), self::scopedEach(['kinds' => [1]], ['kinds' => [7]]));

        $this->assertSame([$newer, $older], $readable->toArray());
    }

    public function testAGroupCutToItsLimitKeepsTheLowerIdOfTwoCreatedAtOnce(): void
    {
        $lower = self::noteWithId(self::NOW - 1, 'a');
        $higher = self::noteWithId(self::NOW - 1, 'b');
        $group = new FilterCollection([self::filter(['kinds' => [1], 'limit' => 1]), self::filter(['kinds' => [EventKind::GIFT_WRAP], 'limit' => 1])]);

        $readable = $this->readGate(new StoredEventCollection([$higher, $lower]), self::NOW)
            ->readableFor($this->client(), ScopedFilters::grouped(new FilterGroupCollection([$group]), false));

        $this->assertSame([$lower], $readable->toArray());
    }

    public function testEventsCreatedAtOnceReadByDifferentGroupsAreListedLowerIdFirst(): void
    {
        $lower = self::noteWithId(self::NOW - 1, 'a');
        $higher = self::noteWithId(self::NOW - 1, 'b');
        $readGate = $this->readGateAnswering(static fn (FilterCollection $filters): array => 7 === $filters->toArray()[0]->getKinds()?->toInts()[0] ? [$lower] : [$higher]);

        $readable = $readGate->readableFor($this->client(), self::scopedEach(['kinds' => [1]], ['kinds' => [7]]));

        $this->assertSame([$lower, $higher], $readable->toArray());
    }

    public function testAnEventTwoGroupsReadIsListedOnce(): void
    {
        $note = $this->noteAt(self::NOW - 10);

        $readable = $this->readGate(new StoredEventCollection([$note]), self::NOW)
            ->readableFor($this->client(), self::scopedEach(['kinds' => [1]], ['authors' => [KeyMother::alicePublicKey()->toHex()]]));

        $this->assertSame([$note], $readable->toArray());
    }

    public function testAStoredGiftWrapReachesAFilterNamingItsKindBesideOneNamingNone(): void
    {
        $giftWrap = StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()));
        $readGate = $this->readGate(new StoredEventCollection([$giftWrap]), self::NOW);

        $readable = $readGate->readableFor($this->client(), self::scopedEach([], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]));

        $this->assertSame([$giftWrap], $readable->toArray());
    }

    public function testAStoredGiftWrapOnlyAFilterNamingNoKindReadsIsWithheldBesideOneNamingItsKind(): void
    {
        $forBob = StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()));
        $forAlice = StoredEvent::of(EventMother::giftWrapFor(KeyMother::alicePublicKey()));
        $readGate = $this->readGateAnswering(static fn (FilterCollection $filters): array => null === $filters->toArray()[0]->getKinds() ? [$forBob, $forAlice] : [$forBob]);

        $readable = $readGate->readableFor($this->client(), self::scopedEach([], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]));

        $this->assertSame([$forBob], $readable->toArray());
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function scoped(array $filter): ScopedFilters
    {
        return ScopedFilters::unchanged(new FilterCollection([self::filter($filter)]));
    }

    /**
     * @param array<string, mixed> ...$filters
     */
    private static function scopedEach(array ...$filters): ScopedFilters
    {
        return ScopedFilters::unchanged(new FilterCollection(array_map(self::filter(...), array_values($filters))));
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function filter(array $filter): Filter
    {
        return Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse');
    }

    /**
     * @param Closure(FilterCollection): list<StoredEvent> $answer
     */
    private function readGateAnswering(Closure $answer): StoredEventReadGate
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturnCallback(static fn (FilterCollection $filters): StoredEventCollection => new StoredEventCollection($answer($filters)));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn(true);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        return new StoredEventReadGate($eventStore, new DeliveryGate($policy, new GiftWrapFilterRules()), $clock);
    }

    private function noteAt(int $createdAt): StoredEvent
    {
        return StoredEvent::of(EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('note at '.$createdAt),
            new TagCollection([]),
            Timestamp::fromInt($createdAt),
        )));
    }

    private static function noteWithId(int $createdAt, string $idDigit): StoredEvent
    {
        $header = new EventHeader(
            EventId::tryFromHex(str_repeat($idDigit, 64)) ?? throw new RuntimeException('event id did not parse'),
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
        );

        return new StoredEvent($header, Timestamp::fromInt($createdAt), null, EncodedEvent::fromOwnStore('{}'));
    }

    private function readGate(StoredEventCollection $stored, int $now, bool $receives = true): StoredEventReadGate
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn($stored);

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn($receives);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        return new StoredEventReadGate($eventStore, new DeliveryGate($policy, new GiftWrapFilterRules()), $clock);
    }

    private function client(): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    private function storedEvent(?int $expiration): StoredEvent
    {
        $tags = null === $expiration ? [] : [Tag::tryFromArray(['expiration', (string) $expiration])];

        return StoredEvent::of(EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection($tags),
            Timestamp::fromInt(self::NOW - 7200),
        )));
    }
}
