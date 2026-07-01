<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
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
        $connection = $this->registry->getConnection($client->getId());

        if (null === $connection) {
            return;
        }

        $connection->sendText($message->toJson());

        if ($message instanceof EventMessage) {
            $this->registry->recordEventSent($client->getId());
        }
    }
}
