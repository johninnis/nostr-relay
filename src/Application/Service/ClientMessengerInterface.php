<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;

interface ClientMessengerInterface
{
    public function send(RelayClient $client, RelayMessage $message): void;

    public function sendEvent(RelayClient $client, SubscriptionId $subscriptionId, EncodedEvent $event): void;
}
