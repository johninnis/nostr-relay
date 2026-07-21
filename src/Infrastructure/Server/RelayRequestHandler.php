<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use Override;
use Psr\Log\LoggerInterface;

final class RelayRequestHandler implements RequestHandler
{
    private const int MESSAGE_SIZE_LIMIT = 128 * 1024;

    private readonly Websocket $websocket;

    // Deliberate: framework adapter wiring the websocket, connection handler, relay document and logger — see ADR-0010
    public function __construct(
        HttpServer $httpServer,
        ClientConnectionHandler $connectionHandler,
        private readonly Nip11HttpHandler $nip11Handler,
        LoggerInterface $logger,
    ) {
        $this->websocket = new Websocket(
            httpServer: $httpServer,
            logger: $logger,
            acceptor: new Rfc6455Acceptor(),
            clientHandler: self::clientHandler($connectionHandler),
            clientFactory: new Rfc6455ClientFactory(
                parserFactory: new Rfc6455ParserFactory(messageSizeLimit: self::MESSAGE_SIZE_LIMIT),
            ),
        );
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

    private static function clientHandler(ClientConnectionHandler $connectionHandler): WebsocketClientHandler
    {
        return new class($connectionHandler) implements WebsocketClientHandler {
            public function __construct(
                private readonly ClientConnectionHandler $handler,
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
        };
    }
}
