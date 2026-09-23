<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;

final readonly class PolicyRejection
{
    private function __construct(
        private ReasonPrefix $reason,
        private string $message,
    ) {
    }

    public static function blocked(string $message): self
    {
        return new self(ReasonPrefix::Blocked, $message);
    }

    public static function authRequired(string $message): self
    {
        return new self(ReasonPrefix::AuthRequired, $message);
    }

    public static function rateLimited(string $message): self
    {
        return new self(ReasonPrefix::RateLimited, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(ReasonPrefix::Invalid, $message);
    }

    public static function restricted(string $message): self
    {
        return new self(ReasonPrefix::Restricted, $message);
    }

    public function isAuthRequired(): bool
    {
        return ReasonPrefix::AuthRequired === $this->reason;
    }

    public function toWireReason(): string
    {
        return $this->reason->format($this->message);
    }
}
