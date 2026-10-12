<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;

final class ActingKeysMother
{
    public static function anonymous(): ActingKeys
    {
        return new ActingKeys(new PublicKeyCollection([]));
    }

    public static function of(PublicKey ...$keys): ActingKeys
    {
        return new ActingKeys(new PublicKeyCollection(array_values($keys)));
    }
}
