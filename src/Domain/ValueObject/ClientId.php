<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class ClientId
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
