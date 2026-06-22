<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
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
