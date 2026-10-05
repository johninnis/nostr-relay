<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;

final class EventHeaderTest extends TestCase
{
    public function testOfCarriesTheEventsId(): void
    {
        $event = self::event();

        self::assertTrue(EventHeader::of($event)->getId()->equals($event->getId()));
    }

    public function testOfCarriesTheEventsAuthor(): void
    {
        self::assertTrue(EventHeader::of(self::event())->getPubkey()->equals(KeyMother::alicePublicKey()));
    }

    public function testOfCarriesTheEventsKind(): void
    {
        self::assertTrue(EventHeader::of(self::event())->getKind()->is(EventKind::REACTION));
    }

    private static function event(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::REACTION),
            EventContent::fromString('+'),
            new TagCollection(),
        ));
    }
}
