<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;

final readonly class PublishingAnswer
{
    private function __construct(
        private ?PolicyRejection $rejection,
        private ?AuthMessage $challenge,
    ) {
    }

    public static function admitted(?AuthMessage $challenge = null): self
    {
        return new self(null, $challenge);
    }

    public static function refused(PolicyRejection $rejection, ?AuthMessage $challenge = null): self
    {
        return new self($rejection, $challenge);
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
}
