<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ClientMessageDispatcher
{
    public function __construct(
        private ClientVerbHandlers $verbHandlers,
        private ClientRegistryInterface $clientRegistry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function dispatch(RelayClient $client, string $rawMessage): array
    {
        try {
            $message = ClientMessage::tryFromJson($rawMessage);
        } catch (InvalidArgumentException $e) {
            return $this->rejectInvalid($client, $rawMessage, $e->getMessage());
        }

        if (null === $message) {
            return $this->rejectInvalid($client, $rawMessage, 'unparseable message');
        }

        if ($message instanceof EventMessage) {
            $this->clientRegistry->recordEventReceived($client->getId());
        }

        $handler = $this->verbHandlers->handlerFor($message);

        if (null === $handler) {
            return [NoticeMessage::fromString('Unknown message type')];
        }

        // Deliberate: the one fault boundary for client messages — use cases frame anticipated rejections, never faults — see ADR-0030
        try {
            return $handler->handle($client, $message);
        } catch (Throwable $e) {
            $this->logger->error('Client message processing failed', [
                'client_id' => (string) $client->getId(),
                'message_type' => $message::class,
                'error' => $e->getMessage(),
            ]);

            return $this->faultReplies($message);
        }
    }

    /**
     * @return list<RelayMessage>
     */
    private function faultReplies(ClientMessage $message): array
    {
        return match (true) {
            $message instanceof EventMessage => [OkMessage::refused($message->getEvent()->getId(), ReasonPrefix::Error, 'could not process event')],
            $message instanceof AuthMessage => [OkMessage::refused($message->getEvent()->getId(), ReasonPrefix::Error, 'could not process authentication')],
            $message instanceof ReqMessage => [ClosedMessage::closed($message->getSubscriptionId(), ReasonPrefix::Error, 'could not process subscription')],
            $message instanceof CountMessage => [ClosedMessage::closed($message->getSubscriptionId(), ReasonPrefix::Error, 'could not count events')],
            $message instanceof CloseMessage => [],
            default => [],
        };
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

        return [NoticeMessage::fromString('Invalid message')];
    }
}
