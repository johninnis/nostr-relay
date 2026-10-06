<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\ExpirationDerivation;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoredEventTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testOfCarriesTheEventsHeader(): void
    {
        $event = self::event([]);

        self::assertEquals(EventHeader::of($event), StoredEvent::of($event)->getHeader());
    }

    public function testOfCarriesTheEventsOwnEncoding(): void
    {
        $event = self::event([]);

        self::assertSame($event->toJson(), StoredEvent::of($event)->getEncoded()->toJson());
    }

    /**
     * @param list<string> $expiries
     */
    #[DataProvider('statedExpiries')]
    public function testItIsExpiredExactlyWhenTheEventItWasBuiltFromIs(array $expiries): void
    {
        $event = self::event($expiries);
        $now = Timestamp::fromInt(self::NOW);

        self::assertSame($event->isExpiredAt($now), StoredEvent::of($event)->isExpiredAt($now));
    }

    /**
     * @param list<string> $expiries
     */
    #[DataProvider('statedExpiries')]
    public function testExpiriesReadFromAnIndexJudgeAsTheEventDoes(array $expiries): void
    {
        $event = self::event($expiries);
        $now = Timestamp::fromInt(self::NOW);
        $fromIndex = new StoredEvent(EventHeader::of($event), ExpirationDerivation::earliestStated($expiries), EncodedEvent::of($event));

        self::assertSame($event->isExpiredAt($now), $fromIndex->isExpiredAt($now));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function statedExpiries(): iterable
    {
        $past = (string) (self::NOW - 1);
        $now = (string) self::NOW;
        $future = (string) (self::NOW + 1);

        yield 'none' => [[]];
        yield 'passed' => [[$past]];
        yield 'at this instant' => [[$now]];
        yield 'in the future' => [[$future]];
        yield 'future then passed' => [[$future, $past]];
        yield 'passed then future' => [[$past, $future]];
        yield 'leading zero' => [['0'.$past]];
        yield 'not a number' => [['soon']];
        yield 'empty' => [['']];
        yield 'signed' => [['-1']];
        yield 'unparseable beside future' => [['soon', $future]];
        yield 'unparseable beside passed' => [['soon', $past]];
        yield 'beyond the integer range' => [['99999999999999999999999']];
    }

    /**
     * @param list<string> $expiries
     */
    private static function event(array $expiries): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection(array_map(static fn (string $value): ?Tag => Tag::tryFromArray(['expiration', $value]), $expiries)),
            Timestamp::fromInt(self::NOW - 7200),
        ));
    }
}
