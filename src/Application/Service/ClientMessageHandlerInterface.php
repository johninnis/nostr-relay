<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

interface ClientMessageHandlerInterface
{
    /**
     * @return class-string<ClientMessage>
     */
    public function handles(): string;

    /**
     * @return list<RelayMessage>
     */
    public function handle(RelayClient $client, ClientMessage $message): array;
}
