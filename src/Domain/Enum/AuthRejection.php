<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Enum;

enum AuthRejection
{
    case InvalidChallenge;
    case InvalidRelayUrl;
    case TimestampOutOfRange;
    case Restricted;

    public function toWireReason(): string
    {
        return match ($this) {
            self::InvalidChallenge => RejectionReason::AuthRequired->format('invalid challenge'),
            self::InvalidRelayUrl => RejectionReason::AuthRequired->format('invalid relay URL'),
            self::TimestampOutOfRange => RejectionReason::AuthRequired->format('timestamp out of range'),
            self::Restricted => RejectionReason::Restricted->format('authentication is limited to relay tenants'),
        };
    }
}
