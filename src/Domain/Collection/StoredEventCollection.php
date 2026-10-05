<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Relay\Domain\ValueObject\StoredEvent;
use Override;

/**
 * @extends TypedCollection<StoredEvent>
 */
final class StoredEventCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return StoredEvent::class;
    }
}
