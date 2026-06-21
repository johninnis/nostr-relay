<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;

final class SubscriptionIdMother
{
    public static function from(string $id): SubscriptionId
    {
        return SubscriptionId::fromString($id) ?? throw new InvalidArgumentException("Invalid subscription id: {$id}");
    }
}
