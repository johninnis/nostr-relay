<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\Enum\RejectionReason;

final readonly class PolicyRejection
{
    private function __construct(
        private RejectionReason $reason,
        private string $message,
    ) {
    }

    public static function blocked(string $message): self
    {
        return new self(RejectionReason::Blocked, $message);
    }

    public static function authRequired(string $message): self
    {
        return new self(RejectionReason::AuthRequired, $message);
    }

    public static function rateLimited(string $message): self
    {
        return new self(RejectionReason::RateLimited, $message);
    }

    public function getReason(): RejectionReason
    {
        return $this->reason;
    }

    public function isAuthRequired(): bool
    {
        return RejectionReason::AuthRequired === $this->reason;
    }

    public function toWireReason(): string
    {
        return $this->reason->format($this->message);
    }
}
