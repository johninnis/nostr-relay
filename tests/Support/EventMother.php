<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use RuntimeException;

final class EventMother
{
    public static function fromRumour(Rumour $rumour): Event
    {
        return new Event($rumour, $rumour->getId(), self::signature());
    }

    public static function signature(): Signature
    {
        return Signature::fromHex(str_repeat('a', 128)) ?? throw new RuntimeException('Invalid fixture signature');
    }
}
