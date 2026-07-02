<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Enum\ClientMessageType;
use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class ClientMessageDispatcher
{
    /** @var array<value-of<ClientMessageType>, ClientMessageHandlerInterface> */
    private array $handlers;

    public function __construct(
        private MessageDeserialiserInterface $deserialiser,
        private LoggerInterface $logger,
        ClientMessageHandlerInterface ...$handlers,
    ) {
        $indexed = [];
        foreach ($handlers as $handler) {
            $indexed[$handler->handles()->value] = $handler;
        }
        $this->handlers = $indexed;
    }

    /**
     * @return list<RelayMessage>
     */
    public function dispatch(RelayClient $client, string $rawMessage): array
    {
        try {
            $message = $this->deserialiser->deserialiseClientMessage($rawMessage);
        } catch (InvalidArgumentException $e) {
            return $this->rejectInvalid($client, $rawMessage, $e->getMessage());
        }

        if (null === $message) {
            return $this->rejectInvalid($client, $rawMessage, 'unparseable message');
        }

        $handler = $this->handlers[$message->type()->value] ?? null;
        if (null === $handler) {
            return [new NoticeMessage('Unknown message type')];
        }

        return $handler->handle($client, $message);
    }

    /**
     * @return list<RelayMessage>
     */
    private function rejectInvalid(RelayClient $client, string $rawMessage, string $reason): array
    {
        $this->logger->warning('Invalid message received', [
            'client_id' => (string) $client->getId(),
            'error' => $reason,
            'message' => mb_substr($rawMessage, 0, 200),
        ]);

        return [new NoticeMessage('Invalid message')];
    }
}
