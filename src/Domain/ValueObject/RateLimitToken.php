<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class RateLimitToken
{
    public function __construct(
        private float $tokens,
        private float $lastRefill,
    ) {
    }

    public function getTokens(): float
    {
        return $this->tokens;
    }

    public function getLastRefill(): float
    {
        return $this->lastRefill;
    }

    public function hasTokens(): bool
    {
        return $this->tokens >= 1.0;
    }

    public function withConsumedToken(): self
    {
        return new self($this->tokens - 1, $this->lastRefill);
    }

    public function withAddedTokens(float $amount, float $refillTime): self
    {
        return new self($this->tokens + $amount, $refillTime);
    }
}
