<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

interface ClientMessengerInterface
{
    public function send(RelayClient $client, RelayMessage $message): void;
}
