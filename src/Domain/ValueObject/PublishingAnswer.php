<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;

final readonly class PublishingAnswer
{
    private function __construct(
        private ?PolicyRejection $rejection,
        private ?AuthMessage $challenge,
        private bool $offersChallenge,
    ) {
    }

    // Deliberate: an admitted event carries the policy's offer, not a challenge, because the challenge is issued only once the store accepts the event — see ADR-0029
    public static function admitted(bool $offersChallenge = false): self
    {
        return new self(null, null, $offersChallenge);
    }

    public static function refused(PolicyRejection $rejection, ?AuthMessage $challenge = null): self
    {
        return new self($rejection, $challenge, false);
    }

    public function isAdmitted(): bool
    {
        return null === $this->rejection;
    }

    public function getRejection(): ?PolicyRejection
    {
        return $this->rejection;
    }

    public function getChallenge(): ?AuthMessage
    {
        return $this->challenge;
    }

    public function offersChallenge(): bool
    {
        return $this->offersChallenge;
    }
}
