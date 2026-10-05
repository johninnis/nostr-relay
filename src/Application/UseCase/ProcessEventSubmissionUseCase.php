<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;

final class ProcessEventSubmissionUseCase implements ClientVerbHandlerInterface
{
    public function __construct(
        private readonly EventAdmission $admission,
        private readonly AcceptedEventPipeline $pipeline,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return class-string<ClientMessage>
     */
    #[Override]
    public function handledMessageType(): string
    {
        return EventMessage::class;
    }

    /**
     * @return list<RelayMessage>
     */
    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        return match (true) {
            $message instanceof EventMessage => $this->execute($client, $message->getEvent()),
            default => throw new InvalidArgumentException('ProcessEventSubmissionUseCase cannot handle '.$message::class),
        };
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, Event $event): array
    {
        $this->logger->debug('Event received', [
            'event_id' => $event->getId()->toHex(),
            'kind' => $event->getKind()->toInt(),
            'pubkey' => $event->getPubkey()->toHex(),
            'client_id' => (string) $client->getId(),
        ]);

        // Deliberate: rejections are framed as this message's wire reply here (OK), not centralised in the router — see ADR-0015
        try {
            $answer = $this->admission->admit($client, $event);
            $rejection = $answer->getRejection();

            if (null !== $rejection) {
                return $this->rejectionReplies($client, $event, $answer);
            }

            $replies = $this->pipeline->accept($client, $event);

            if (null !== $answer->getChallenge()) {
                array_unshift($replies, $answer->getChallenge());
            }

            return $replies;
        } catch (InvalidEventException $e) {
            $this->logger->warning('Event invalid', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex(), 'reason' => $e->getMessage()]);

            return [OkMessage::refused($event->getId(), ReasonPrefix::Invalid, $e->getMessage())];
        }
    }

    /**
     * @return list<RelayMessage>
     */
    private function rejectionReplies(RelayClient $client, Event $event, PublishingAnswer $answer): array
    {
        $rejection = $answer->getRejection() ?? throw new InvalidArgumentException('A rejected answer carries a rejection');
        $challenge = $answer->getChallenge();

        if ($rejection->isAuthRequired()) {
            $this->logger->debug('Event auth-required', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex()]);

            return [
                ...(null === $challenge ? [] : [$challenge]),
                $rejection->toOkMessage($event->getId()),
            ];
        }

        $this->logger->warning('Event rejected', [
            'event_id' => $event->getId()->toHex(),
            'pubkey' => $event->getPubkey()->toHex(),
            'kind' => $event->getKind()->toInt(),
            'reason' => $rejection->toWireReason(),
        ]);

        return [$rejection->toOkMessage($event->getId())];
    }
}
