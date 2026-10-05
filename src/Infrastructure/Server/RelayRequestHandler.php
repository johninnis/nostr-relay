<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Websocket;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use Override;

final class RelayRequestHandler implements RequestHandler
{
    public function __construct(
        private readonly Websocket $websocket,
        private readonly Nip11HttpHandler $nip11Handler,
    ) {
    }

    #[Override]
    public function handleRequest(Request $request): Response
    {
        $relayInformation = $this->nip11Handler->handleRequest($request);

        if (null !== $relayInformation) {
            return $relayInformation;
        }

        return $this->websocket->handleRequest($request);
    }
}
