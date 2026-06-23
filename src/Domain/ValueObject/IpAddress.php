<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use InvalidArgumentException;
use Override;
use Stringable;

final readonly class IpAddress implements Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return self::tryFromString($value)
            ?? throw new InvalidArgumentException(sprintf('Invalid IP address: %s', $value));
    }

    public static function tryFromString(string $value): ?self
    {
        return false !== filter_var($value, FILTER_VALIDATE_IP) ? new self($value) : null;
    }

    public function isIpV6(): bool
    {
        return false !== filter_var($this->value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
