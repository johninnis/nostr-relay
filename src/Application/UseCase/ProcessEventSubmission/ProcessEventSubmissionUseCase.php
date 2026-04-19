<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidationService;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\Service\TagReferenceExtractor;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

final class ProcessEventSubmissionUseCase
{
    private readonly EventValidationService $eventValidator;

    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly EventDistributor $distributor,
        private readonly AuthenticationManager $authManager,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
        SignatureServiceInterface $signatureService,
    ) {
        $this->eventValidator = new EventValidationService($signatureService, new NipComplianceValidator($signatureService));
    }

    private function processDeletion(Event $event): void
    {
        $references = TagReferenceExtractor::extract($event->getTags());
        $author = $event->getPubkey();
        $deletedCount = 0;

        $eventIds = array_map(
            static fn ($ref) => $ref->getEventId(),
            $references->getEvents()
        );

        if (!empty($eventIds)) {
            $deletedCount += $this->eventStore->deleteByEventIds($eventIds, $author);
        }

        $coordinates = $references->getAddressable();

        if (!empty($coordinates)) {
            $deletedCount += $this->eventStore->deleteByCoordinates($coordinates, $author);
        }

        $referenceCount = count($eventIds) + count($coordinates);

        if ($deletedCount > 0) {
            $this->logger->debug('Deletion event processed', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $event->getPubkey()->toHex(),
                'referenced' => $referenceCount,
                'deleted_count' => $deletedCount,
            ]);
        } elseif ($referenceCount > 0) {
            $this->logger->debug('Deletion event had no effect', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $event->getPubkey()->toHex(),
                'referenced' => $referenceCount,
            ]);
        }
    }

    public function execute(RelayClient $client, Event $event): void
    {
        $eventId = $event->getId()->toHex();
        $kind = $event->getKind()->toInt();
        $clientId = (string) $client->getId();

        $this->logger->debug('Event received', [
            'event_id' => $eventId,
            'kind' => $kind,
            'pubkey' => $event->getPubkey()->toHex(),
            'client_id' => $clientId,
        ]);

        try {
            if (!$this->policy->isRateLimitExempt($client)) {
                $this->rateLimiter->checkLimit($client->getConnectionInfo()->getIpAddress());
            }

            $this->eventValidator->validateEvent($event);

            $this->policy->allowEventSubmission($client, $event);

            if ($event->getKind()->isEphemeral()) {
                $this->metrics->incrementEventsReceived();
                async(fn () => $this->distributor->distributeToSubscribers($event));
                $client->send(new OkMessage($event->getId(), true, ''));
                $this->logger->debug('Event accepted (ephemeral)', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);

                return;
            }

            $stored = $this->eventStore->store($event);

            if ($stored) {
                $this->metrics->incrementEventsReceived();

                if ($event->isDeletion()) {
                    $this->processDeletion($event);
                }

                async(fn () => $this->distributor->distributeToSubscribers($event));

                $client->send(new OkMessage($event->getId(), true, ''));
                $this->logger->debug('Event stored', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'kind' => $kind]);
            } else {
                $client->send(new OkMessage($event->getId(), false, 'duplicate: event already exists'));
                $this->logger->debug('Event duplicate', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
            }
        } catch (InvalidEventException $e) {
            $client->send(new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage()));
            $this->logger->warning('Event invalid', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'reason' => $e->getMessage()]);
        } catch (AuthRequiredException) {
            $alreadyChallenged = null !== $this->authManager->getChallenge($client->getId());
            $challenge = $this->authManager->generateChallenge($client->getId());
            if (!$alreadyChallenged) {
                $client->send(new AuthMessage($challenge));
            }
            $client->send(new OkMessage($event->getId(), false, 'auth-required: authentication required'));
            $this->logger->debug('Event auth-required', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'challenged' => !$alreadyChallenged]);
        } catch (PolicyViolationException $e) {
            $client->send(new OkMessage($event->getId(), false, 'blocked: '.$e->getMessage()));
            $this->logger->warning('Event blocked', [
                'event_id' => $eventId,
                'pubkey' => $event->getPubkey()->toHex(),
                'kind' => $kind,
                'reason' => $e->getMessage(),
            ]);
        } catch (RateLimitException) {
            $client->send(new OkMessage($event->getId(), false, 'rate-limited: slow down'));
            $this->logger->warning('Event rate-limited', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
        } catch (Throwable $e) {
            $client->send(new OkMessage($event->getId(), false, 'error: could not process event'));
            $this->logger->error('Event processing error', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'error' => $e->getMessage()]);
        }
    }
}
