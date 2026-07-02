<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Enum;

enum AuthRejection: string
{
    case InvalidChallenge = 'auth-required: invalid challenge';
    case InvalidRelayUrl = 'auth-required: invalid relay URL';
    case TimestampOutOfRange = 'auth-required: timestamp out of range';
    case Restricted = 'restricted: authentication is limited to relay tenants';
}
