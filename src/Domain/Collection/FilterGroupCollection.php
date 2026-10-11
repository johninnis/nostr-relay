<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<FilterCollection>
 */
final class FilterGroupCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return FilterCollection::class;
    }
}
