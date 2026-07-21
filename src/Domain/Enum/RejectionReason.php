<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Enum;

enum RejectionReason: string
{
    case Invalid = 'invalid';
    case Blocked = 'blocked';
    case RateLimited = 'rate-limited';
    case AuthRequired = 'auth-required';
    case Error = 'error';
    case Duplicate = 'duplicate';
    case Restricted = 'restricted';

    public function format(string $detail): string
    {
        return $this->value.': '.$detail;
    }
}
