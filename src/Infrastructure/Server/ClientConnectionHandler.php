<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Psr\Log\LoggerInterface;
use Throwable;

final class ClientConnectionHandler
{
    private const int DEFAULT_IDLE_TIMEOUT_SECONDS = 300;

    // Deliberate: per-connection lifecycle coordinates registry, disconnection, router, gate, logging and timeout — see ADR-0010
    public function __construct(
        private readonly InMemoryClientRegistry $clientManager,
        private readonly ClientDisconnectionHandler $disconnectionHandler,
        private readonly MessageRouter $messageRouter,
        private readonly LoggerInterface $logger,
        private readonly ConnectionGateInterface $connectionGate,
        private readonly int $idleTimeoutSeconds = self::DEFAULT_IDLE_TIMEOUT_SECONDS,
    ) {
    }

    public function handle(WebsocketClient $websocketClient, string $ipAddress, string $userAgent): void
    {
        try {
            $clientIp = IpAddress::fromString($ipAddress);

            if (!$this->connectionGate->isIpAllowed($clientIp)) {
                throw ConnectionException::ipBlocked($clientIp);
            }

            $connectionInfo = new ConnectionInfo($clientIp, $userAgent, Timestamp::now());

            $adapter = new WebsocketClientConnection($websocketClient);
            $client = $this->clientManager->registerClient($adapter, $connectionInfo);

            while ($message = $websocketClient->receive(new TimeoutCancellation($this->idleTimeoutSeconds))) {
                $this->messageRouter->route($client, $message->buffer());
            }
        } catch (CancelledException) {
            $this->logger->info('Client idle timeout', ['ip' => $ipAddress]);
        } catch (ConnectionException $e) {
            $this->logger->warning('Client rejected', [
                'ip' => $ipAddress,
                'reason' => $e->getMessage(),
            ]);
            $websocketClient->sendText(new NoticeMessage($e->getMessage())->toJson());
        } catch (Throwable $e) {
            $this->logger->error('Client connection error', [
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if (isset($client)) {
                $this->disconnectionHandler->disconnect($client->getId());
            }

            try {
                $websocketClient->close(WebsocketCloseCode::NORMAL_CLOSE, 'Connection closed');
            } catch (Throwable $e) {
                $this->logger->debug('Websocket already closed during cleanup', [
                    'ip' => $ipAddress,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
