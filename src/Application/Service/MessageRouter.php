<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class MessageRouter
{
    public function __construct(
        private readonly ClientMessageDispatcher $dispatcher,
        private readonly ClientMessengerInterface $messenger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function route(RelayClient $client, string $message): void
    {
        try {
            foreach ($this->dispatcher->dispatch($client, $message) as $reply) {
                $this->messenger->send($client, $reply);
            }
        } catch (Throwable $e) {
            $this->messenger->send($client, new NoticeMessage('Internal server error'));
            $this->logger->error('Message routing error', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
                'message' => mb_substr($message, 0, 200),
            ]);
        }
    }
}
