<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\RecordingClientMessenger;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StoredEventStreamerTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAnEventWithNoExpiryIsSent(): void
    {
        $this->assertCount(1, $this->eventsStreamedFor(expiration: null));
    }

    public function testAnEventThatExpiredWhileStoredIsWithheld(): void
    {
        $this->assertSame([], $this->eventsStreamedFor(expiration: self::NOW - 1));
    }

    public function testAnEventIsWithheldAtTheExpiryInstant(): void
    {
        $this->assertSame([], $this->eventsStreamedFor(expiration: self::NOW));
    }

    public function testAnEventExpiringOneSecondLaterIsStillSent(): void
    {
        $this->assertCount(1, $this->eventsStreamedFor(expiration: self::NOW + 1));
    }

    public function testExpiryIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $this->assertCount(1, $this->eventsStreamedFor(expiration: self::NOW + 1, now: self::NOW - 3600));
    }

    public function testTheSubscriptionStillReachesEndOfStoredEventsWhenEverythingIsWithheld(): void
    {
        $sent = $this->stream(new EventCollection([$this->event(self::NOW - 1)]), self::NOW);

        $this->assertCount(1, $sent);
        $this->assertInstanceOf(EoseMessage::class, $sent[0]);
    }

    public function testALiveEventIsSentAlongsideAWithheldOne(): void
    {
        $live = $this->event(null);
        $sent = $this->stream(new EventCollection([$this->event(self::NOW - 1), $live]), self::NOW);

        $this->assertCount(2, $sent);
        $this->assertInstanceOf(EventMessage::class, $sent[0]);
        $this->assertTrue($sent[0]->getEvent()->getId()->equals($live->getId()));
    }

    /**
     * @return list<EventMessage>
     */
    private function eventsStreamedFor(?int $expiration, int $now = self::NOW): array
    {
        $sent = $this->stream(new EventCollection([$this->event($expiration)]), $now);

        return array_values(array_filter($sent, static fn (RelayMessage $message): bool => $message instanceof EventMessage));
    }

    /**
     * @return list<RelayMessage>
     */
    private function stream(EventCollection $stored, int $now): array
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn($stored);

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn(true);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        $metrics = $this->createStub(MetricsCollectorInterface::class);
        $subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, new NullLogger());
        $clientRegistry = new InMemoryClientRegistry($metrics, new NativeRandomBytesGenerator(), new NullLogger());
        $client = $clientRegistry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );

        $messenger = new RecordingClientMessenger();

        $filters = new FilterCollection([new Filter()]);
        $subscription = Subscription::create(SubscriptionIdMother::from('sub-1'), $filters);
        $subscriptionRegistry->addSubscription($client->getId(), $subscription);

        new StoredEventStreamer($eventStore, $policy, $messenger, $subscriptionRegistry, $clock, new NullLogger())
            ->stream($client, $subscription, $filters);

        return $messenger->sent();
    }

    private function event(?int $expiration): Event
    {
        $tags = null === $expiration ? [] : [Tag::tryFromArray(['expiration', (string) $expiration])];

        return EventMother::fromRumour(new Rumour(
            KeyMother::alicePublicKey(),
            Timestamp::fromInt(self::NOW - 7200),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            new TagCollection($tags),
            EventContent::fromString('hello'),
        ));
    }
}
