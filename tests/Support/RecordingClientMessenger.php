<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Service\ClientMessengerInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Override;

final class RecordingClientMessenger implements ClientMessengerInterface
{
    /** @var list<string> */
    private array $frames = [];

    #[Override]
    public function send(RelayClient $client, RelayMessage $message): void
    {
        $this->frames[] = $message->toJson();
    }

    #[Override]
    public function sendEvent(RelayClient $client, SubscriptionId $subscriptionId, EncodedEvent $event): void
    {
        $this->frames[] = $event->framedFor($subscriptionId);
    }

    /**
     * @return list<string>
     */
    public function frames(): array
    {
        return $this->frames;
    }
}
