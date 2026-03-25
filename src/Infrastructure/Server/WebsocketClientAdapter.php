<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Websocket\WebsocketClient;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;

final readonly class WebsocketClientAdapter implements ClientConnectionInterface
{
    public function __construct(
        private WebsocketClient $websocketClient,
    ) {
    }

    public function sendText(string $text): void
    {
        $this->websocketClient->sendText($text);
    }

    public function close(): void
    {
        $this->websocketClient->close();
    }
}
