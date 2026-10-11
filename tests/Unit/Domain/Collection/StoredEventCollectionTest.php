<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Collection;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\Collection\StoredEventCollection;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoredEventCollectionTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testNewestFirstListsANewerEventBeforeAnOlderOne(): void
    {
        $older = self::storedAt(self::NOW - 1, 'a');
        $newer = self::storedAt(self::NOW, 'b');

        $this->assertSame([$newer, $older], new StoredEventCollection([$older, $newer])->newestFirst()->toArray());
    }

    public function testNewestFirstListsTheLowerIdFirstOfTwoCreatedAtOnce(): void
    {
        $lower = self::storedAt(self::NOW, 'a');
        $higher = self::storedAt(self::NOW, 'b');

        $this->assertSame([$lower, $higher], new StoredEventCollection([$higher, $lower])->newestFirst()->toArray());
    }

    private static function storedAt(int $createdAt, string $idDigit): StoredEvent
    {
        $header = new EventHeader(
            EventId::tryFromHex(str_repeat($idDigit, 64)) ?? throw new RuntimeException('event id did not parse'),
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
        );

        return new StoredEvent($header, Timestamp::fromInt($createdAt), null, EncodedEvent::fromOwnStore('{}'));
    }
}
