<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Override;

// Deliberate: the Live state is a property of the delivery path, flipped here and nowhere else — see ADR-0031
final readonly class LiveMarkingClientMessenger implements ClientMessengerInterface
{
    public function __construct(
        private ClientMessengerInterface $messenger,
        private SubscriptionRegistryInterface $subscriptionRegistry,
    ) {
    }

    #[Override]
    public function send(RelayClient $client, RelayMessage $message): void
    {
        $this->messenger->send($client, $message);

        if ($message instanceof EoseMessage) {
            $this->subscriptionRegistry->updateSubscriptionState($client->getId(), $message->getSubscriptionId(), SubscriptionState::Live);
        }
    }

    #[Override]
    public function sendEvent(RelayClient $client, SubscriptionId $subscriptionId, EncodedEvent $event): void
    {
        $this->messenger->sendEvent($client, $subscriptionId, $event);
    }
}
