<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Service\MessageSerialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final class MessageRouter
{
    public function __construct(
        private readonly ProcessEventSubmissionUseCase $processEventSubmissionUseCase,
        private readonly CreateSubscriptionUseCase $createSubscriptionUseCase,
        private readonly CloseSubscriptionUseCase $closeSubscriptionUseCase,
        private readonly MessageSerialiserInterface $serialiser,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function route(RelayClient $client, string $message): void
    {
        try {
            $clientMessage = $this->serialiser->deserialiseClientMessage($message);

            match (true) {
                $clientMessage instanceof EventMessage => $this->processEventSubmissionUseCase->execute(
                    $client,
                    $clientMessage->getEvent()
                ),
                $clientMessage instanceof ReqMessage => $this->createSubscriptionUseCase->execute(
                    $client,
                    $clientMessage->getSubscriptionId(),
                    $clientMessage->getFilters()
                ),
                $clientMessage instanceof CloseMessage => $this->closeSubscriptionUseCase->execute(
                    $client,
                    $clientMessage->getSubscriptionId()
                ),
                default => $client->send(new NoticeMessage('Unknown message type')),
            };
        } catch (InvalidArgumentException $e) {
            $client->send(new NoticeMessage('Invalid message: '.$e->getMessage()));
            $this->logger->warning('Invalid message received', [
                'client_id' => $client->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $client->send(new NoticeMessage('Internal server error'));
            $this->logger->error('Message routing error', [
                'client_id' => $client->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
