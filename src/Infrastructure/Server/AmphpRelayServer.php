<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Middleware\Forwarded;
use Amp\Http\Server\Middleware\ForwardedHeaderType;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Amp\Socket\BindContext;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use Psr\Log\LoggerInterface;

final class AmphpRelayServer
{
    public function __construct(
        private readonly RelayConfigInterface $config,
        private readonly ClientConnectionHandler $connectionHandler,
        private readonly Nip11HttpHandler $nip11Handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function start(): void
    {
        $host = $this->config->getHost();
        $port = $this->config->getPort();

        $this->logger->info('Starting Nostr relay server', [
            'host' => $host,
            'port' => $port,
            'max_connections' => $this->config->getMaxConnections(),
        ]);

        $server = SocketHttpServer::createForBehindProxy(
            $this->logger,
            ForwardedHeaderType::XForwardedFor,
            $this->config->getTrustedProxies(),
        );

        $bindContext = (new BindContext())->withTcpNoDelay();

        $server->expose(new Socket\InternetAddress($host, $port), $bindContext);

        $clientHandler = new class($this->connectionHandler) implements WebsocketClientHandler {
            public function __construct(
                private readonly ClientConnectionHandler $handler,
            ) {
            }

            public function handleClient(
                WebsocketClient $client,
                Request $request,
                Response $response,
            ): void {
                $forwarded = $request->hasAttribute(Forwarded::class)
                    ? $request->getAttribute(Forwarded::class)
                    : null;
                $ipAddress = $forwarded instanceof Forwarded
                    ? $forwarded->getFor()->getAddress()
                    : $request->getClient()->getRemoteAddress()->toString();
                $userAgent = $request->getHeader('user-agent') ?? 'unknown';

                $this->handler->handle($client, $ipAddress, $userAgent);
            }
        };

        $clientFactory = new Rfc6455ClientFactory(
            parserFactory: new Rfc6455ParserFactory(
                messageSizeLimit: 128 * 1024,
            ),
        );

        $websocket = new Websocket(
            httpServer: $server,
            logger: $this->logger,
            acceptor: new Rfc6455Acceptor(),
            clientHandler: $clientHandler,
            clientFactory: $clientFactory,
        );

        $requestHandler = new ClosureRequestHandler(
            function (Request $request) use ($websocket): Response {
                if ('GET' === $request->getMethod() && '/' === $request->getUri()->getPath()) {
                    $acceptHeader = $request->getHeader('accept') ?? '';

                    if (str_contains($acceptHeader, 'application/nostr+json')) {
                        return $this->nip11Handler->handle();
                    }
                }

                return $websocket->handleRequest($request);
            }
        );

        $errorHandler = new DefaultErrorHandler();

        try {
            $server->start($requestHandler, $errorHandler);
        } catch (Socket\SocketException $e) {
            throw ConnectionException::bindFailed($host, $port, $e);
        }

        $this->logger->info('Relay server started successfully');

        $signal = \Amp\trapSignal([SIGINT, SIGTERM]);
        $this->logger->info('Received shutdown signal', ['signal' => $signal]);
        $server->stop();
    }
}
