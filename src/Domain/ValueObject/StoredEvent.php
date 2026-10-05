<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class StoredEvent
{
    public function __construct(
        private EventHeader $header,
        private ?Timestamp $expiresAt,
        private EncodedEvent $encoded,
    ) {
    }

    public static function of(Event $event): self
    {
        return new self(
            EventHeader::of($event),
            self::earliestExpiry($event->getTags()->getValuesByType(TagType::expiration())),
            EncodedEvent::of($event),
        );
    }

    /**
     * @param iterable<string> $statedExpiries
     */
    public static function earliestExpiry(iterable $statedExpiries): ?Timestamp
    {
        $earliest = null;

        foreach ($statedExpiries as $value) {
            $expiry = Timestamp::tryFromDecimalString($value);

            if (null !== $expiry && (null === $earliest || $expiry->isBefore($earliest))) {
                $earliest = $expiry;
            }
        }

        return $earliest;
    }

    public function getHeader(): EventHeader
    {
        return $this->header;
    }

    public function getEncoded(): EncodedEvent
    {
        return $this->encoded;
    }

    public function isExpiredAt(Timestamp $reference): bool
    {
        return $this->expiresAt?->hasPassedAt($reference) ?? false;
    }
}
