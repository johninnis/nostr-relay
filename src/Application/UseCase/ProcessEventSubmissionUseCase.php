<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AuthChallengeInterface;
use Innis\Nostr\Relay\Application\Service\ClientRegistryInterface;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessEventSubmissionUseCase
{
    // Deliberate: submission handler — admit, hand the accepted event to the pipeline, and frame failures as this message's OK reply per ADR-0003; the residual collaborators (gate, pipeline, challenge, registry, logger) are distinct concerns.
    public function __construct(
        private readonly EventAdmission $admission,
        private readonly AcceptedEventPipeline $pipeline,
        private readonly AuthChallengeInterface $authChallenge,
        private readonly ClientRegistryInterface $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, Event $event): array
    {
        $eventId = $event->getId()->toHex();
        $kind = $event->getKind()->toInt();

        $this->registry->recordEventReceived($client->getId());

        $this->logger->debug('Event received', [
            'event_id' => $eventId,
            'kind' => $kind,
            'pubkey' => $event->getPubkey()->toHex(),
            'client_id' => (string) $client->getId(),
        ]);

        // Deliberate: rejections are framed as this message's wire reply here (OK), not centralised in the router — see ADR-0003
        try {
            $this->admission->admit($client, $event);

            return $this->pipeline->accept($client, $event);
        } catch (InvalidEventException $e) {
            $this->logger->warning('Event invalid', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'reason' => $e->getMessage()]);

            return [new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage())];
        } catch (AuthRequiredException) {
            $this->logger->debug('Event auth-required', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);

            $replies = [];
            if (null === $this->authChallenge->getChallenge($client->getId())) {
                $replies[] = new AuthMessage($this->authChallenge->getOrCreateChallenge($client->getId()));
            }
            $replies[] = new OkMessage($event->getId(), false, 'auth-required: authentication required');

            return $replies;
        } catch (PolicyViolationException $e) {
            $this->logger->warning('Event blocked', [
                'event_id' => $eventId,
                'pubkey' => $event->getPubkey()->toHex(),
                'kind' => $kind,
                'reason' => $e->getMessage(),
            ]);

            return [new OkMessage($event->getId(), false, 'blocked: '.$e->getMessage())];
        } catch (RateLimitException) {
            $this->logger->warning('Event rate-limited', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);

            return [new OkMessage($event->getId(), false, 'rate-limited: slow down')];
        } catch (Throwable $e) {
            $this->logger->error('Event processing error', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'error' => $e->getMessage()]);

            return [new OkMessage($event->getId(), false, 'error: could not process event')];
        }
    }
}
