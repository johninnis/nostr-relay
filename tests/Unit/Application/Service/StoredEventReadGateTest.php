<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

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
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\ActingKeysMother;
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

        $readable = $this->readGate(new StoredEventCollection([$stored]), self::NOW)->readableFor($this->client(), self::readOf([]));

        $this->assertSame([$stored], $readable->toArray());
    }

    public function testAnEventExpiredAtNowIsWithheld(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(self::NOW)]), self::NOW)
            ->readableFor($this->client(), self::readOf([]));

        $this->assertSame([], $readable->toArray());
    }

    public function testAnEventThePolicyRefusesIsWithheld(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(null)]), self::NOW, receives: false)
            ->readableFor($this->client(), self::readOf([]));

        $this->assertSame([], $readable->toArray());
    }

    public function testExpiryIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(self::NOW + 1)]), self::NOW - 3600)
            ->readableFor($this->client(), self::readOf([]));

        $this->assertCount(1, $readable);
    }

    public function testTheStoreIsReadOnceForEveryFilterOfTheRequest(): void
    {
        $read = self::readOf(['kinds' => [1]], ['kinds' => [EventKind::GIFT_WRAP]]);
        $eventStore = $this->createMock(RelayEventStoreInterface::class);
        $eventStore->expects($this->once())->method('findByFilters')->with($read->getFilters(), $read->getReader())->willReturn(new StoredEventCollection([]));

        $this->readGateOver($eventStore, true)->readableFor($this->client(), $read);
    }

    public function testEventsTheStoreReturnsAreListedNewestFirst(): void
    {
        $older = $this->noteAt(self::NOW - 20);
        $newer = $this->noteAt(self::NOW - 10);

        $readable = $this->readGate(new StoredEventCollection([$older, $newer]), self::NOW)->readableFor($this->client(), self::readOf(['kinds' => [1]]));

        $this->assertSame([$newer, $older], $readable->toArray());
    }

    public function testEventsCreatedAtOnceAreListedLowerIdFirst(): void
    {
        $lower = self::noteWithId(self::NOW - 1, 'a');
        $higher = self::noteWithId(self::NOW - 1, 'b');

        $readable = $this->readGate(new StoredEventCollection([$higher, $lower]), self::NOW)->readableFor($this->client(), self::readOf(['kinds' => [1]]));

        $this->assertSame([$lower, $higher], $readable->toArray());
    }

    /**
     * @param array<string, mixed> ...$filters
     */
    private static function readOf(array ...$filters): AdmittedRead
    {
        return new AdmittedRead(
            ScopedFilters::unchanged(new FilterCollection(array_map(self::filter(...), array_values($filters)))),
            ActingKeysMother::of(KeyMother::bobPublicKey()),
        );
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function filter(array $filter): Filter
    {
        return Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse');
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

        return $this->readGateOver($eventStore, $receives, $now);
    }

    private function readGateOver(RelayEventStoreInterface $eventStore, bool $receives, int $now = self::NOW): StoredEventReadGate
    {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn($receives);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        return new StoredEventReadGate($eventStore, new DeliveryGate($policy), $clock);
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
