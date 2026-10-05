<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Relay\Domain\ValueObject\EventRecipient;
use Override;

/**
 * @extends TypedCollection<EventRecipient>
 */
final class EventRecipientCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return EventRecipient::class;
    }
}
