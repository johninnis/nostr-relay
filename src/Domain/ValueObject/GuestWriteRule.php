<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;

final readonly class GuestWriteRule
{
    public function __construct(
        private EventKindCollection $kinds,
        private bool $taggedToTenant,
    ) {
    }

    public function appliesToKind(EventKind $kind): bool
    {
        return $this->kinds->contains($kind);
    }

    public function requiresTenantTag(): bool
    {
        return $this->taggedToTenant;
    }
}
