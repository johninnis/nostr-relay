<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Infrastructure\EventStore;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\EventCount;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Infrastructure\EventStore\LoggingEventStore;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LoggingEventStoreTest extends TestCase
{
    public function testStoreForwardsAndReturnsTheOutcome(): void
    {
        $event = EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection(),
        ));

        $inner = $this->createMock(RelayEventStoreInterface::class);
        $inner->expects($this->once())->method('store')->with($event)->willReturn(EventStoreOutcome::Stored);

        $store = new LoggingEventStore($inner, new NullLogger());

        $this->assertSame(EventStoreOutcome::Stored, $store->store($event));
    }

    public function testFindByFiltersForwardsToTheInnerStore(): void
    {
        $filters = new FilterCollection([Filter::from()]);
        $stored = new StoredEventCollection([]);

        $inner = $this->createMock(RelayEventStoreInterface::class);
        $inner->expects($this->once())->method('findByFilters')->with($filters)->willReturn($stored);

        $store = new LoggingEventStore($inner, new NullLogger());

        $this->assertSame($stored, $store->findByFilters($filters));
    }

    public function testCountByFiltersForwardsToTheInnerStore(): void
    {
        $filters = new FilterCollection([Filter::from()]);

        $inner = $this->createStub(RelayEventStoreInterface::class);
        $inner->method('countByFilters')->willReturn(EventCount::exact(3));

        $store = new LoggingEventStore($inner, new NullLogger());

        $this->assertSame(3, $store->countByFilters($filters)->toInt());
    }
}
