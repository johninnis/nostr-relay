<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Override;
use Stringable;

final readonly class ClientId implements Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        return new self(bin2hex($bytes));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
