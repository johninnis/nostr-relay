<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LiveAndStoredDeliveryAgreeTest extends TestCase
{
    private const int NOW = 1_700_000_000;
    private const string ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const string BOB = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const string CAROL = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
    private const string AUTHOR = 'd4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4';

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function reads(): iterable
    {
        foreach (['no keys' => [], 'Alice' => [self::ALICE], 'Alice and Carol' => [self::ALICE, self::CAROL]] as $readerName => $readerKeys) {
            yield "a filter naming no kind, read by {$readerName}" => [[], $readerKeys];
            yield "a filter naming the gift-wrap kinds, read by {$readerName}" => [['kinds' => [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP]], $readerKeys];
            yield "a filter mixing kinds, read by {$readerName}" => [['kinds' => [EventKind::TEXT_NOTE, EventKind::GIFT_WRAP]], $readerKeys];
            yield "a #p filter naming Alice, read by {$readerName}" => [['#p' => [self::ALICE]], $readerKeys];
            yield "a #p filter naming Bob, read by {$readerName}" => [['#p' => [self::BOB]], $readerKeys];
            yield "an ids lookup of every event, read by {$readerName}" => [['ids' => array_map(static fn (Event $event): string => $event->getId()->toHex(), self::events())], $readerKeys];
        }
    }

    /**
     * @param array<string, mixed> $filter
     * @param list<string>         $readerKeys
     */
    #[DataProvider('reads')]
    public function testALiveEventReachesASubscriptionExactlyWhenItsStoredReadWouldReturnIt(array $filter, array $readerKeys): void
    {
        $filters = new FilterCollection([Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse')]);
        $reader = new ActingKeys(PublicKeyCollection::fromHexValues($readerKeys));

        $this->assertSame($this->storedIds($filters, $reader), $this->liveIds($filters, $reader));
    }

    /**
     * @return list<string>
     */
    private function storedIds(FilterCollection $filters, ActingKeys $reader): array
    {
        $eventStore = new InMemoryEventStore();

        foreach (self::events() as $event) {
            $eventStore->store($event);
        }

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        return self::sorted(array_map(
            static fn (StoredEvent $event): string => $event->getHeader()->getId()->toHex(),
            new StoredEventReadGate($eventStore, $this->deliveryGate(), $clock)->readableFor(self::client(), new AdmittedRead(ScopedFilters::unchanged($filters), $reader))->toArray(),
        ));
    }

    /**
     * @return list<string>
     */
    private function liveIds(FilterCollection $filters, ActingKeys $reader): array
    {
        $match = new SubscriptionMatch(self::client()->getId(), Subscription::create(SubscriptionIdMother::from('live'), $filters), $reader);

        return self::sorted(array_map(
            static fn (Event $event): string => $event->getId()->toHex(),
            array_values(array_filter(self::events(), fn (Event $event): bool => $this->deliveryGate()->allowsLive(self::client(), $match, $event))),
        ));
    }

    private function deliveryGate(): DeliveryGate
    {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn(true);

        return new DeliveryGate($policy);
    }

    /**
     * @return list<Event>
     */
    private static function events(): array
    {
        return [
            self::event(EventKind::TEXT_NOTE, 100),
            self::event(EventKind::TEXT_NOTE, 110, self::ALICE),
            self::event(EventKind::TEXT_NOTE, 120, self::BOB),
            self::event(EventKind::GIFT_WRAP, 130, self::ALICE),
            self::event(EventKind::GIFT_WRAP, 140, self::BOB),
            self::event(EventKind::GIFT_WRAP, 150, self::CAROL),
            self::event(EventKind::GIFT_WRAP, 160, self::BOB, self::ALICE),
            self::event(EventKind::EPHEMERAL_GIFT_WRAP, 170, self::ALICE),
            self::event(EventKind::EPHEMERAL_GIFT_WRAP, 180, self::BOB),
        ];
    }

    private static function event(int $kind, int $createdAt, string ...$tagged): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            PublicKey::tryFromHex(self::AUTHOR) ?? throw new RuntimeException('public key did not parse'),
            EventKind::fromInt($kind),
            EventContent::fromString('agreement'),
            new TagCollection(array_map(
                static fn (string $key): Tag => Tag::pubkey(PublicKey::tryFromHex($key) ?? throw new RuntimeException('public key did not parse')),
                array_values($tagged),
            )),
            Timestamp::fromInt($createdAt),
        ));
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

    private static function client(): RelayClient
    {
        return new RelayClient(
            ClientId::fromString('reader'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::fromInt(self::NOW)),
        );
    }
}
