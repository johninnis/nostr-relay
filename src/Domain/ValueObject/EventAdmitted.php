<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class EventAdmitted
{
    public function __construct(
        private bool $challengeOffered,
    ) {
    }

    public function isChallengeOffered(): bool
    {
        return $this->challengeOffered;
    }
}
