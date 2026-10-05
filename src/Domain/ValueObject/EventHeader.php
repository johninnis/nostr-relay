<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class EventHeader
{
    public function __construct(
        private EventId $id,
        private PublicKey $pubkey,
        private EventKind $kind,
    ) {
    }

    public static function of(Event $event): self
    {
        return new self($event->getId(), $event->getPubkey(), $event->getKind());
    }

    public function getId(): EventId
    {
        return $this->id;
    }

    public function getPubkey(): PublicKey
    {
        return $this->pubkey;
    }

    public function getKind(): EventKind
    {
        return $this->kind;
    }
}
