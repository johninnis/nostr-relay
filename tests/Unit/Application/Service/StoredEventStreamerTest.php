<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientMessenger;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StoredEventStreamerTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private const string EOSE = '["EOSE","sub-1"]';

    public function testAnEventWithNoExpiryIsSent(): void
    {
        $this->assertCount(1, $this->eventFramesStreamedFor(expiration: null));
    }

    public function testAnEventThatExpiredWhileStoredIsWithheld(): void
    {
        $this->assertSame([], $this->eventFramesStreamedFor(expiration: self::NOW - 1));
    }

    public function testAnEventIsWithheldAtTheExpiryInstant(): void
    {
        $this->assertSame([], $this->eventFramesStreamedFor(expiration: self::NOW));
    }

    public function testAnEventExpiringOneSecondLaterIsStillSent(): void
    {
        $this->assertCount(1, $this->eventFramesStreamedFor(expiration: self::NOW + 1));
    }

    public function testExpiryIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $this->assertCount(1, $this->eventFramesStreamedFor(expiration: self::NOW + 1, now: self::NOW - 3600));
    }

    public function testTheSubscriptionStillReachesEndOfStoredEventsWhenEverythingIsWithheld(): void
    {
        $this->assertSame([self::EOSE], $this->stream(new StoredEventCollection([$this->storedEvent(self::NOW - 1)]), self::NOW));
    }

    public function testALiveEventIsSentAlongsideAWithheldOne(): void
    {
        $live = $this->storedEvent(null);

        $frames = $this->stream(new StoredEventCollection([$this->storedEvent(self::NOW - 1), $live]), self::NOW);

        $this->assertSame([$live->getEncoded()->framedFor(SubscriptionIdMother::from('sub-1')), self::EOSE], $frames);
    }

    public function testAnEventThePolicyRefusesIsWithheld(): void
    {
        $this->assertSame([self::EOSE], $this->stream(new StoredEventCollection([$this->storedEvent(null)]), self::NOW, receives: false));
    }

    public function testStoredBytesAreSentAsTheStoreHoldsThemWithoutBeingParsed(): void
    {
        $header = EventHeader::of(EventMother::fromRumour($this->rumour(null)));
        $stored = new StoredEvent($header, null, EncodedEvent::fromOwnStore('{"stored":"bytes, not reparsed"}'));

        $frames = $this->stream(new StoredEventCollection([$stored]), self::NOW);

        $this->assertSame('["EVENT","sub-1",{"stored":"bytes, not reparsed"}]', $frames[0]);
    }

    /**
     * @return list<string>
     */
    private function eventFramesStreamedFor(?int $expiration, int $now = self::NOW): array
    {
        return array_values(array_filter(
            $this->stream(new StoredEventCollection([$this->storedEvent($expiration)]), $now),
            static fn (string $frame): bool => self::EOSE !== $frame,
        ));
    }

    /**
     * @return list<string>
     */
    private function stream(StoredEventCollection $stored, int $now, bool $receives = true): array
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn($stored);

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn($receives);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        $clientRegistry = new InMemoryClientRegistry(
            $this->createStub(MetricsCollectorInterface::class),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );
        $client = $clientRegistry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $messenger = new RecordingClientMessenger();

        $filters = new FilterCollection([Filter::from()]);
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), $filters);

        new StoredEventStreamer(new StoredEventReadGate($eventStore, $policy, $clock), $messenger, new NullLogger())
            ->stream($client, $subscription, $filters);

        return $messenger->frames();
    }

    private function storedEvent(?int $expiration): StoredEvent
    {
        return StoredEvent::of(EventMother::fromRumour($this->rumour($expiration)));
    }

    private function rumour(?int $expiration): Rumour
    {
        $tags = null === $expiration ? [] : [Tag::tryFromArray(['expiration', (string) $expiration])];

        return Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection($tags),
            Timestamp::fromInt(self::NOW - 7200),
        );
    }
}
