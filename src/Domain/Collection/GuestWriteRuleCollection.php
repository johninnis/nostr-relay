<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Relay\Domain\ValueObject\GuestWriteRule;
use Override;

/**
 * @extends TypedCollection<GuestWriteRule>
 */
final class GuestWriteRuleCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return GuestWriteRule::class;
    }
}
