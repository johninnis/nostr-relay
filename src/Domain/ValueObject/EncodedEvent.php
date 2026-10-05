<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

final readonly class EncodedEvent
{
    private function __construct(
        private string $json,
    ) {
    }

    public static function of(Event $event): self
    {
        return new self($event->toJson());
    }

    // Deliberate: trusted by provenance and never parsed — only bytes this relay wrote with of() may be passed here — see ADR-0021
    public static function fromOwnStore(string $json): self
    {
        return new self($json);
    }

    public function toJson(): string
    {
        return $this->json;
    }

    public function framedFor(SubscriptionId $subscriptionId): string
    {
        $prefix = JsonWireFormat::encode([RelayMessageType::Event->value, (string) $subscriptionId], JsonWireFormat::MESSAGE);

        return substr($prefix, 0, -1).','.$this->json.']';
    }
}
