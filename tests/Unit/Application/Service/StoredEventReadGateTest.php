<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;

final class StoredEventReadGateTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAReadableEventIsReturned(): void
    {
        $stored = $this->storedEvent(null);

        $readable = $this->readGate(new StoredEventCollection([$stored]), self::NOW)->readableFor($this->client(), new FilterCollection([Filter::from()]));

        $this->assertSame([$stored], $readable->toArray());
    }

    public function testAnEventExpiredAtNowIsWithheld(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(self::NOW)]), self::NOW)
            ->readableFor($this->client(), new FilterCollection([Filter::from()]));

        $this->assertSame([], $readable->toArray());
    }

    public function testAnEventThePolicyRefusesIsWithheld(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(null)]), self::NOW, receives: false)
            ->readableFor($this->client(), new FilterCollection([Filter::from()]));

        $this->assertSame([], $readable->toArray());
    }

    public function testExpiryIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $readable = $this->readGate(new StoredEventCollection([$this->storedEvent(self::NOW + 1)]), self::NOW - 3600)
            ->readableFor($this->client(), new FilterCollection([Filter::from()]));

        $this->assertCount(1, $readable);
    }

    private function readGate(StoredEventCollection $stored, int $now, bool $receives = true): StoredEventReadGate
    {
        $eventStore = $this->createStub(RelayEventStoreInterface::class);
        $eventStore->method('findByFilters')->willReturn($stored);

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('canClientReceiveEvent')->willReturn($receives);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        return new StoredEventReadGate($eventStore, $policy, $clock);
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
