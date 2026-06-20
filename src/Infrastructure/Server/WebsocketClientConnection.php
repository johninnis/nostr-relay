<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\ByteStream\StreamException;
use Amp\Websocket\WebsocketClient;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Override;

final readonly class WebsocketClientConnection implements ClientConnectionInterface
{
    public function __construct(
        private WebsocketClient $websocketClient,
    ) {
    }

    #[Override]
    public function sendText(string $text): void
    {
        try {
            $this->websocketClient->sendText($text);
        } catch (StreamException $e) {
            throw ConnectionException::peerDisconnected($e);
        }
    }
}
