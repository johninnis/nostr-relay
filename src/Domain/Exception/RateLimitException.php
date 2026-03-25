<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Exception;

final class RateLimitException extends RelayException
{
    public static function forKey(string $key): self
    {
        return new self("Rate limit exceeded for '{$key}'");
    }
}
