<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Relay\Domain\Collection\GuestWriteRuleCollection;

final readonly class GuestPolicy
{
    public function __construct(
        private EventKindCollection $readableKinds,
        private bool $fromTenantsOnly,
        private GuestWriteRuleCollection $writeRules,
    ) {
    }

    public function getReadableKinds(): EventKindCollection
    {
        return $this->readableKinds;
    }

    public function readsFromTenantsOnly(): bool
    {
        return $this->fromTenantsOnly;
    }

    public function getWriteRules(): GuestWriteRuleCollection
    {
        return $this->writeRules;
    }
}
