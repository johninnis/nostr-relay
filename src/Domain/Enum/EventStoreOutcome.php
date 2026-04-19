<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Enum;

enum EventStoreOutcome: string
{
    case Stored = 'stored';
    case Duplicate = 'duplicate';
    case Superseded = 'superseded';
}
