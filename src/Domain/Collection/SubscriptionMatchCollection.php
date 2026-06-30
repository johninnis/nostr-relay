<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Relay\Domain\ValueObject\SubscriptionMatch;
use Override;

/**
 * @extends TypedCollection<SubscriptionMatch>
 */
final class SubscriptionMatchCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return SubscriptionMatch::class;
    }
}
