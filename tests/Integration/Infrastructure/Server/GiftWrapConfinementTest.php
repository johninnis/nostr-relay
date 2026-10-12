<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Infrastructure\Server;

use Amp\Http\Server\SocketHttpServer;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\EventLimits;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Service\ClientSessionCoordinator;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Innis\Nostr\Relay\Tests\Support\GiftWrapMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function Amp\delay;

final class GiftWrapConfinementTest extends TestCase
{
    private const string SUBSCRIPTION = 'reads';
    private const array GIFT_WRAP_KINDS = [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP];

    private ClientSessionCoordinator $sessions;
    private InMemoryAuthenticationRegistry $authentication;

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function readersAndFilters(): iterable
    {
        foreach (['a guest' => false, 'Alice' => true] as $readerName => $authenticatedAsAlice) {
            foreach (self::filterShapes() as $shapeName => $filter) {
                yield "{$shapeName}, read by {$readerName}" => [$filter, $authenticatedAsAlice];
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, int}>
     */
    public static function counts(): iterable
    {
        $expected = [
            'a filter naming no kind' => [1, 3],
            'an ids filter naming every gift wrap' => [0, 2],
            'a #p filter naming Bob' => [1, 2],
            'an authors filter naming the gift wraps\' signer' => [0, 2],
        ];

        foreach (self::filterShapes() as $shapeName => $filter) {
            yield "{$shapeName}, counted by a guest" => [$filter, false, $expected[$shapeName][0]];
            yield "{$shapeName}, counted by Alice" => [$filter, true, $expected[$shapeName][1]];
        }
    }

    protected function setUp(): void
    {
        $this->authentication = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getMaxConnections')->willReturn(100);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));
        $config->method('getEventLimits')->willReturn(new EventLimits());

        $this->sessions = new RelayServerFactory(
            eventStore: new InMemoryEventStore(),
            policy: new RelayPolicy($this->authentication, new NullLogger(), RelayPolicyConfig::tryFromArray([]) ?? throw new RuntimeException('policy config did not parse')),
            config: $config,
        )
            ->withAuthenticationRegistry($this->authentication)
            ->withRateLimitPolicy(new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 1000, subscriptionsPerMinute: 1000)))
            ->create(SocketHttpServer::createForDirectAccess(new NullLogger()))
            ->getSessionCoordinator();
    }

    /**
     * @param array<string, mixed> $filter
     */
    #[DataProvider('readersAndFilters')]
    public function testAStoredReadReturnsNoGiftWrapAddressedToAnotherKey(array $filter, bool $authenticatedAsAlice): void
    {
        $this->publish(...self::storedWorld());

        $this->assertSame([], self::leakedTo($this->read($filter, $authenticatedAsAlice), $authenticatedAsAlice));
    }

    /**
     * @param array<string, mixed> $filter
     */
    #[DataProvider('readersAndFilters')]
    public function testLiveDeliveryOfGiftWrapsSendsNoneAddressedToAnotherKey(array $filter, bool $authenticatedAsAlice): void
    {
        $this->assertSame([], self::leakedTo($this->receiveLive($filter, $authenticatedAsAlice, EventKind::GIFT_WRAP), $authenticatedAsAlice));
    }

    /**
     * @param array<string, mixed> $filter
     */
    #[DataProvider('readersAndFilters')]
    public function testLiveDeliveryOfEphemeralGiftWrapsSendsNoneAddressedToAnotherKey(array $filter, bool $authenticatedAsAlice): void
    {
        $this->assertSame([], self::leakedTo($this->receiveLive($filter, $authenticatedAsAlice, EventKind::EPHEMERAL_GIFT_WRAP), $authenticatedAsAlice));
    }

    /**
     * @param array<string, mixed> $filter
     */
    #[DataProvider('counts')]
    public function testACountCountsNoGiftWrapAddressedToAnotherKey(array $filter, bool $authenticatedAsAlice, int $expected): void
    {
        $this->publish(...self::storedWorld());

        $this->assertSame($expected, $this->countedBy($filter, $authenticatedAsAlice));
    }

    public function testAGuestReadsOnlyTheNoteThroughAFilterNamingNoKind(): void
    {
        $this->publish(...self::storedWorld());

        $this->assertSame([GiftWrapMother::noteTaggingBob()->getId()->toHex()], self::idsOf($this->read([], false)));
    }

    public function testAGuestReceivesOnlyTheLiveNoteThroughAFilterNamingNoKind(): void
    {
        $connection = new RecordingClientConnection();
        $this->sessions->route($this->reader($connection, false), self::frame('REQ', []));
        delay(0.01);

        $this->publish(GiftWrapMother::noteTaggingBob(), ...self::liveWorld(...self::GIFT_WRAP_KINDS));

        $this->assertSame([GiftWrapMother::noteTaggingBob()->getId()->toHex()], self::idsOf(self::eventsIn($connection->sentFrames())));
    }

    public function testAliceReadsHerOwnStoredGiftWrapsAndNoEphemeralOneThroughAFilterNamingNoKind(): void
    {
        $this->publish(...self::storedWorld());

        $this->assertSame(
            self::sortedIds(GiftWrapMother::storedToAlice(), GiftWrapMother::storedToBobAndAlice()),
            self::giftWrapIds($this->read([], true)),
        );
    }

    public function testAliceReceivesHerOwnLiveGiftWrapsOfBothKindsThroughAFilterNamingNoKind(): void
    {
        $this->assertSame(
            self::sortedIds(...array_merge(...array_map(
                static fn (int $kind): array => [GiftWrapMother::liveToAlice($kind), GiftWrapMother::liveToBobAndAlice($kind)],
                self::GIFT_WRAP_KINDS,
            ))),
            self::giftWrapIds($this->receiveLive([], true, ...self::GIFT_WRAP_KINDS)),
        );
    }

    public function testAStoredGiftWrapReachesAliceThroughAPFilterNamingBobOnlyWhenItAlsoNamesAlice(): void
    {
        $this->publish(...self::storedWorld());

        $this->assertSame(
            self::sortedIds(GiftWrapMother::storedToBobAndAlice()),
            self::giftWrapIds($this->read(['#p' => [KeyMother::bobPublicKey()->toHex()]], true)),
        );
    }

    public function testAStoredGiftWrapReachesAliceThroughAFilterMixingKindsAndNamingBobOnlyWhenItAlsoNamesAlice(): void
    {
        $this->publish(...self::storedWorld());

        $this->assertSame(
            self::sortedIds(GiftWrapMother::storedToBobAndAlice()),
            self::giftWrapIds($this->read(['kinds' => [EventKind::TEXT_NOTE, EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]], true)),
        );
    }

    public function testAFilterNamingOnlyTheGiftWrapKindAndBobIsRefusedToAliceAsAuthRequired(): void
    {
        $this->publish(...self::storedWorld());
        $connection = new RecordingClientConnection();
        $this->sessions->route($this->reader($connection, true), self::frame('REQ', ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]));
        delay(0.01);

        $this->assertStringStartsWith('["CLOSED","reads","auth-required:', $connection->sentFrames()[array_key_last($connection->sentFrames()) ?? 0] ?? '');
    }

    public function testALiveGiftWrapOfEitherKindReachesAliceThroughAPFilterNamingBobOnlyWhenItAlsoNamesAlice(): void
    {
        $this->assertSame(
            self::sortedIds(...array_map(GiftWrapMother::liveToBobAndAlice(...), self::GIFT_WRAP_KINDS)),
            self::giftWrapIds($this->receiveLive(['#p' => [KeyMother::bobPublicKey()->toHex()]], true, ...self::GIFT_WRAP_KINDS)),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function filterShapes(): array
    {
        return [
            'a filter naming no kind' => [],
            'an ids filter naming every gift wrap' => ['ids' => array_map(static fn (Event $event): string => $event->getId()->toHex(), GiftWrapMother::everyWrap())],
            'a #p filter naming Bob' => ['#p' => [KeyMother::bobPublicKey()->toHex()]],
            'an authors filter naming the gift wraps\' signer' => ['authors' => [GiftWrapMother::wrapSigner()->toHex()]],
        ];
    }

    /**
     * @return list<Event>
     */
    private static function storedWorld(): array
    {
        return [
            GiftWrapMother::storedToAlice(),
            GiftWrapMother::storedToBob(),
            GiftWrapMother::storedToBobAndAlice(),
            GiftWrapMother::noteTaggingBob(),
            ...array_map(GiftWrapMother::liveToAlice(...), [EventKind::EPHEMERAL_GIFT_WRAP]),
        ];
    }

    /**
     * @return list<Event>
     */
    private static function liveWorld(int ...$kinds): array
    {
        return array_merge(...array_map(
            static fn (int $kind): array => [GiftWrapMother::liveToAlice($kind), GiftWrapMother::liveToBob($kind), GiftWrapMother::liveToBobAndAlice($kind)],
            $kinds,
        ));
    }

    private function publish(Event ...$events): void
    {
        $publisher = $this->sessions->open(new RecordingClientConnection(), self::connectionInfo());

        foreach ($events as $event) {
            $this->sessions->route($publisher, '["EVENT",'.$event->toJson().']');
        }

        delay(0.01);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    private function read(array $filter, bool $authenticatedAsAlice): array
    {
        $connection = new RecordingClientConnection();
        $this->sessions->route($this->reader($connection, $authenticatedAsAlice), self::frame('REQ', $filter));
        delay(0.01);

        return self::eventsIn($connection->sentFrames());
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    private function receiveLive(array $filter, bool $authenticatedAsAlice, int ...$kinds): array
    {
        $connection = new RecordingClientConnection();
        $this->sessions->route($this->reader($connection, $authenticatedAsAlice), self::frame('REQ', $filter));
        delay(0.01);

        $this->publish(...self::liveWorld(...$kinds));

        return self::eventsIn($connection->sentFrames());
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function countedBy(array $filter, bool $authenticatedAsAlice): int
    {
        $connection = new RecordingClientConnection();
        $this->sessions->route($this->reader($connection, $authenticatedAsAlice), self::frame('COUNT', $filter));
        delay(0.01);

        foreach ($connection->sentFrames() as $frame) {
            $message = json_decode($frame, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($message) && 'COUNT' === ($message[0] ?? null) && is_array($message[2] ?? null) && is_int($message[2]['count'] ?? null)) {
                return $message[2]['count'];
            }
        }

        throw new RuntimeException('The relay answered the COUNT with no count');
    }

    private function reader(RecordingClientConnection $connection, bool $authenticatedAsAlice): RelayClient
    {
        $client = $this->sessions->open($connection, self::connectionInfo());

        if ($authenticatedAsAlice) {
            $this->authentication->authenticate($client->getId(), KeyMother::alicePublicKey());
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function frame(string $verb, array $filter): string
    {
        return json_encode([$verb, self::SUBSCRIPTION, (object) $filter], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $frames
     *
     * @return list<array<string, mixed>>
     */
    private static function eventsIn(array $frames): array
    {
        $events = [];

        foreach ($frames as $frame) {
            $message = json_decode($frame, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($message) && 'EVENT' === ($message[0] ?? null) && is_array($message[2] ?? null)) {
                /** @var array<string, mixed> $event */
                $event = $message[2];
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function leakedTo(array $events, bool $authenticatedAsAlice): array
    {
        $readerKeys = $authenticatedAsAlice ? [KeyMother::alicePublicKey()->toHex()] : [];

        return self::idsOf(array_values(array_filter(
            self::giftWrapsIn($events),
            static fn (array $event): bool => [] === array_intersect(self::pTagValuesOf($event), $readerKeys),
        )));
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function giftWrapIds(array $events): array
    {
        $ids = self::idsOf(self::giftWrapsIn($events));
        sort($ids);

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function giftWrapsIn(array $events): array
    {
        return array_values(array_filter($events, static fn (array $event): bool => in_array($event['kind'] ?? null, self::GIFT_WRAP_KINDS, true)));
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return list<string>
     */
    private static function pTagValuesOf(array $event): array
    {
        $tags = is_array($event['tags'] ?? null) ? $event['tags'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $tag): ?string => is_array($tag) && 'p' === ($tag[0] ?? null) && is_string($tag[1] ?? null) ? $tag[1] : null,
            $tags,
        )));
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function idsOf(array $events): array
    {
        return array_map(static fn (array $event): string => is_string($event['id'] ?? null) ? $event['id'] : '', $events);
    }

    /**
     * @return list<string>
     */
    private static function sortedIds(Event ...$events): array
    {
        $ids = array_map(static fn (Event $event): string => $event->getId()->toHex(), array_values($events));
        sort($ids);

        return $ids;
    }

    private static function connectionInfo(): ConnectionInfo
    {
        return new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now());
    }
}
