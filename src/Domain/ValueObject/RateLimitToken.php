<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class RateLimitToken
{
    private const SECONDS_PER_MINUTE = 60.0;

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

    public function refilled(float $now, float $capacity): self
    {
        $elapsed = $now - $this->lastRefill;
        $tokensToAdd = min($capacity - $this->tokens, $elapsed * ($capacity / self::SECONDS_PER_MINUTE));

        return new self($this->tokens + $tokensToAdd, $now);
    }
}
