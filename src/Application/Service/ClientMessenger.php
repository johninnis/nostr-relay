<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use InvalidArgumentException;
use Override;

final readonly class ClientMessenger implements ClientMessengerInterface
{
    public function __construct(
        private ClientRegistryInterface $registry,
    ) {
    }

    #[Override]
    public function send(RelayClient $client, RelayMessage $message): void
    {
        if ($message instanceof EventMessage) {
            throw new InvalidArgumentException('An EVENT frame is sent with sendEvent() from an encoded event, so every event reaches the wire one way');
        }

        $this->sendText($client, $message->toJson());
    }

    #[Override]
    public function sendEvent(RelayClient $client, SubscriptionId $subscriptionId, EncodedEvent $event): void
    {
        if ($this->sendText($client, $event->framedFor($subscriptionId))) {
            $this->registry->recordEventSent($client->getId());
        }
    }

    private function sendText(RelayClient $client, string $text): bool
    {
        $connection = $this->registry->getConnection($client->getId());

        if (null === $connection) {
            return false;
        }

        $connection->sendText($text);

        return true;
    }
}
