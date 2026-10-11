<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use RuntimeException;

final class EventMother
{
    public static function fromRumour(Rumour $rumour): Event
    {
        return new Event($rumour, $rumour->getId(), self::signature());
    }

    public static function giftWrapFor(PublicKey $recipient): Event
    {
        return self::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::GIFT_WRAP),
            EventContent::fromString('sealed'),
            new TagCollection([Tag::pubkey($recipient)]),
        ));
    }

    public static function signature(): Signature
    {
        return Signature::tryFromHex(str_repeat('a', 128)) ?? throw new RuntimeException('Invalid fixture signature');
    }
}
