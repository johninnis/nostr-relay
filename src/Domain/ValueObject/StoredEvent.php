<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class StoredEvent
{
    public function __construct(
        private EventHeader $header,
        private Timestamp $createdAt,
        private ?Timestamp $expiresAt,
        private EncodedEvent $encoded,
    ) {
    }

    public static function of(Event $event): self
    {
        return new self(
            EventHeader::of($event),
            $event->getCreatedAt(),
            $event->expiresAt(),
            EncodedEvent::of($event),
        );
    }

    public function getHeader(): EventHeader
    {
        return $this->header;
    }

    public function getEncoded(): EncodedEvent
    {
        return $this->encoded;
    }

    // Deliberate: newest first, and of two created at once the lower id first, the order NIP-01 gives a REQ's stored events — see ADR-0021
    public function precedes(self $other): bool
    {
        $newer = $this->createdAt->toInt() <=> $other->createdAt->toInt();

        return 1 === $newer || (0 === $newer && strcmp($this->header->getId()->toHex(), $other->header->getId()->toHex()) < 0);
    }

    public function isExpiredAt(Timestamp $reference): bool
    {
        return $this->expiresAt?->hasPassedAt($reference) ?? false;
    }
}
