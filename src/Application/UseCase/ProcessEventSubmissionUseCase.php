<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\ClientRegistryInterface;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\RejectionReason;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessEventSubmissionUseCase
{
    // Deliberate: submission handler coordinates admission, pipeline, challenge, registry and logging — see ADR-0010
    public function __construct(
        private readonly EventAdmission $admission,
        private readonly AcceptedEventPipeline $pipeline,
        private readonly AuthChallengeIssuer $authChallengeIssuer,
        private readonly ClientRegistryInterface $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, Event $event): array
    {
        $this->registry->recordEventReceived($client->getId());

        $this->logger->debug('Event received', [
            'event_id' => $event->getId()->toHex(),
            'kind' => $event->getKind()->toInt(),
            'pubkey' => $event->getPubkey()->toHex(),
            'client_id' => (string) $client->getId(),
        ]);

        // Deliberate: rejections are framed as this message's wire reply here (OK), not centralised in the router — see ADR-0003
        try {
            $outcome = $this->admission->admit($client, $event);

            if ($outcome instanceof PolicyRejection) {
                return $this->rejectionReplies($client, $event, $outcome);
            }

            $replies = $this->pipeline->accept($client, $event);

            if ($outcome->isChallengeOffered()) {
                array_unshift($replies, $this->authChallengeIssuer->issue($client->getId()));
            }

            return $replies;
        } catch (InvalidEventException $e) {
            $this->logger->warning('Event invalid', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex(), 'reason' => $e->getMessage()]);

            return [new OkMessage($event->getId(), false, RejectionReason::Invalid->format($e->getMessage()))];
        } catch (Throwable $e) {
            $this->logger->error('Event processing error', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex(), 'error' => $e->getMessage()]);

            return [new OkMessage($event->getId(), false, RejectionReason::Error->format('could not process event'))];
        }
    }

    /**
     * @return list<RelayMessage>
     */
    private function rejectionReplies(RelayClient $client, Event $event, PolicyRejection $rejection): array
    {
        if ($rejection->isAuthRequired()) {
            $this->logger->debug('Event auth-required', ['event_id' => $event->getId()->toHex(), 'pubkey' => $event->getPubkey()->toHex()]);

            $replies = [];
            $challenge = $this->authChallengeIssuer->issueIfUnchallenged($client->getId());
            if (null !== $challenge) {
                $replies[] = $challenge;
            }
            $replies[] = new OkMessage($event->getId(), false, $rejection->toWireReason());

            return $replies;
        }

        $this->logger->warning('Event rejected', [
            'event_id' => $event->getId()->toHex(),
            'pubkey' => $event->getPubkey()->toHex(),
            'kind' => $event->getKind()->toInt(),
            'reason' => $rejection->toWireReason(),
        ]);

        return [new OkMessage($event->getId(), false, $rejection->toWireReason())];
    }
}
