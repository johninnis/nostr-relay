<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class ClientMessageDispatcher
{
    // Deliberate: dispatch table holds one use case per protocol verb plus the deserialiser and logger — the breadth is the protocol — see ADR-0010
    public function __construct(
        private MessageDeserialiserInterface $deserialiser,
        private LoggerInterface $logger,
        private ProcessEventSubmissionUseCase $processEvent,
        private CreateSubscriptionUseCase $createSubscription,
        private CloseSubscriptionUseCase $closeSubscription,
        private ProcessAuthUseCase $processAuth,
        private CountSubscriptionUseCase $countSubscription,
    ) {
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

        return match (true) {
            $message instanceof EventMessage => $this->processEvent->execute($client, $message->getEvent()),
            $message instanceof ReqMessage => $this->createSubscription->execute($client, $message->getSubscriptionId(), $message->getFilters()),
            $message instanceof CloseMessage => $this->closeSubscription->execute($client, $message->getSubscriptionId()),
            $message instanceof AuthMessage => $this->processAuth->execute($client, $message->getEvent()),
            $message instanceof CountMessage => $this->countSubscription->execute($client, $message->getSubscriptionId(), $message->getFilters()),
            default => [new NoticeMessage('Unknown message type')],
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

        return [new NoticeMessage('Invalid message')];
    }
}
