<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Override;

final readonly class SessionWebsocketClientHandler implements WebsocketClientHandler
{
    public function __construct(
        private ClientConnectionHandler $handler,
    ) {
    }

    #[Override]
    public function handleClient(
        WebsocketClient $client,
        Request $request,
        Response $response,
    ): void {
        $forwarded = $request->hasAttribute(Forwarded::class)
            ? $request->getAttribute(Forwarded::class)
            : null;
        $remoteAddress = $request->getClient()->getRemoteAddress();
        $ipAddress = $forwarded instanceof Forwarded
            ? $forwarded->getFor()->getAddress()
            : ($remoteAddress instanceof Socket\InternetAddress
                ? $remoteAddress->getAddress()
                : $remoteAddress->toString());

        $this->handler->handle($client, $ipAddress, $request->getHeader('user-agent') ?? 'unknown');
    }
}
