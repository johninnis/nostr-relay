<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

final readonly class SessionCounters
{
    public function __construct(
        private int $eventsReceived,
        private int $eventsAccepted,
        private int $eventsSent,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    public function getEventsReceived(): int
    {
        return $this->eventsReceived;
    }

    public function getEventsAccepted(): int
    {
        return $this->eventsAccepted;
    }

    public function getEventsSent(): int
    {
        return $this->eventsSent;
    }

    public function withEventReceived(): self
    {
        return new self($this->eventsReceived + 1, $this->eventsAccepted, $this->eventsSent);
    }

    public function withEventAccepted(): self
    {
        return new self($this->eventsReceived, $this->eventsAccepted + 1, $this->eventsSent);
    }

    public function withEventSent(): self
    {
        return new self($this->eventsReceived, $this->eventsAccepted, $this->eventsSent + 1);
    }
}
