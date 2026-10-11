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
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\DeliveryGate;
use Innis\Nostr\Relay\Application\Service\HeldSubscriptionSender;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientMessenger;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use Innis\Nostr\Relay\Tests\Support\TestDoubleException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class StoredEventStreamerTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private const string EOSE = '["EOSE","sub-1"]';

    private InMemorySubscriptionRegistry $subscriptionRegistry;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->subscriptionRegistry = new InMemorySubscriptionRegistry($this->createStub(MetricsCollectorInterface::class), new NullLogger());
        $this->client = new InMemoryClientRegistry(
            $this->createStub(MetricsCollectorInterface::class),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        )->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

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
        $stored = new StoredEvent($header, Timestamp::fromInt(self::NOW - 7200), null, EncodedEvent::fromOwnStore('{"stored":"bytes, not reparsed"}'));

        $frames = $this->stream(new StoredEventCollection([$stored]), self::NOW);

        $this->assertSame('["EVENT","sub-1",{"stored":"bytes, not reparsed"}]', $frames[0]);
    }

    public function testAStoreFaultEndsTheSubscriptionWithAClosedError(): void
    {
        $this->assertSame(['["CLOSED","sub-1","error: could not read stored events"]'], $this->streamWhileTheStoreFails());
    }

    public function testAStoreFaultWithdrawsTheSubscription(): void
    {
        $this->streamWhileTheStoreFails();

        $this->assertSame(0, $this->subscriptionRegistry->getSubscriptionCountForClient($this->client->getId()));
    }

    public function testAReadBeyondScopeEndsWithAnAuthHintedEose(): void
    {
        $frames = $this->stream(new StoredEventCollection([]), self::NOW, scoped: ScopedFilters::scoped(new FilterCollection([Filter::from()]), true));

        $this->assertSame('["EOSE","sub-1",["auth"]]', $frames[count($frames) - 1] ?? null);
    }

    public function testAReadBeyondScopeIsOfferedAChallengeJustBeforeItsEose(): void
    {
        $frames = $this->stream(new StoredEventCollection([]), self::NOW, scoped: ScopedFilters::scoped(new FilterCollection([Filter::from()]), true));

        $this->assertNotNull(AuthMessage::tryFromJson($frames[0]));
    }

    public function testAStoredGiftWrapIsWithheldFromARequestNamingNoKind(): void
    {
        $giftWrap = StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()));

        $this->assertSame([self::EOSE], $this->stream(new StoredEventCollection([$giftWrap]), self::NOW));
    }

    public function testAStoredGiftWrapIsWithheldFromARequestNamingAnotherKeyWithoutAKind(): void
    {
        $giftWrap = StoredEvent::of(EventMother::giftWrapFor(KeyMother::bobPublicKey()));
        $filters = new FilterCollection([Filter::tryFromArray(['#p' => [KeyMother::bobPublicKey()->toHex()]]) ?? throw new RuntimeException('filter did not parse')]);

        $this->assertSame([self::EOSE], $this->stream(new StoredEventCollection([$giftWrap]), self::NOW, scoped: ScopedFilters::unchanged($filters)));
    }

    public function testAReadWhoseSubscriptionAReqReplacedWhileItWasReadSendsNothing(): void
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturnCallback(function (): StoredEventCollection {
            $this->register();

            return new StoredEventCollection([$this->storedEvent(null)]);
        });

        $this->assertSame([], $this->streamFrom($eventStore, self::NOW, true, ScopedFilters::unchanged(new FilterCollection([Filter::from()]))));
    }

    public function testAFaultOnAReadWhoseSubscriptionAReqReplacedWhileItWasReadSendsNothing(): void
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturnCallback(function (): never {
            $this->register();

            throw new TestDoubleException('store unavailable');
        });

        $this->assertSame([], $this->streamFrom($eventStore, self::NOW, true, ScopedFilters::unchanged(new FilterCollection([Filter::from()]))));
    }

    /**
     * @return list<string>
     */
    private function streamWhileTheStoreFails(): array
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willThrowException(new TestDoubleException('store unavailable'));

        return $this->streamFrom($eventStore, self::NOW, true, ScopedFilters::unchanged(new FilterCollection([Filter::from()])));
    }

    private function register(): Subscription
    {
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), new FilterCollection([Filter::from()]));
        $this->subscriptionRegistry->addSubscription($this->client->getId(), $subscription);

        return $subscription;
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
    private function stream(StoredEventCollection $stored, int $now, bool $receives = true, ?ScopedFilters $scoped = null): array
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn($stored);

        return $this->streamFrom($eventStore, $now, $receives, $scoped ?? ScopedFilters::unchanged(new FilterCollection([Filter::from()])));
    }

    /**
     * @return list<string>
     */
    private function streamFrom(RelayEventStoreInterface $eventStore, int $now, bool $receives, ScopedFilters $scoped): array
    {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn($receives);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        $messenger = new RecordingClientMessenger();
        $answers = new SubscriptionAnswers(
            $this->subscriptionRegistry,
            new AuthChallengeIssuer(new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator())),
            new NullLogger(),
        );

        new StoredEventStreamer(
            new StoredEventReadGate($eventStore, new DeliveryGate($policy, new GiftWrapFilterRules()), $clock),
            new HeldSubscriptionSender($messenger, $this->subscriptionRegistry, $answers),
        )->stream($this->client, $this->register(), $scoped);

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
