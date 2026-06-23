<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Relay\Application\Port\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Port\ClientRegistryInterface;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessEventSubmissionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly EventAdmission $admission,
        private readonly EventDistributor $distributor,
        private readonly AuthenticationManager $authManager,
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
        private readonly ClientRegistryInterface $registry,
        private readonly ClientMessengerInterface $messenger,
        private readonly DeferredExecutorInterface $deferredExecutor,
        private readonly EventDeletionProcessor $deletionProcessor,
    ) {
    }

    public function execute(RelayClient $client, Event $event): void
    {
        $eventId = $event->getId()->toHex();
        $kind = $event->getKind()->toInt();
        $clientId = (string) $client->getId();

        $this->registry->recordEventReceived($client->getId());

        $this->logger->debug('Event received', [
            'event_id' => $eventId,
            'kind' => $kind,
            'pubkey' => $event->getPubkey()->toHex(),
            'client_id' => $clientId,
        ]);

        // Deliberate: rejections are caught and framed as this message's wire reply here (OK), not centralised in the router — see ADR-0003
        try {
            $this->admission->admit($client, $event);

            if ($event->getKind()->isEphemeral()) {
                $this->metrics->incrementEventsReceived();
                $this->registry->recordEventAccepted($client->getId());
                $this->deferredExecutor->defer(fn () => $this->distributor->distributeToSubscribers($event));
                $this->messenger->send($client, new OkMessage($event->getId(), true, ''));
                $this->logger->debug('Event accepted (ephemeral)', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);

                return;
            }

            $outcome = $this->eventStore->store($event);

            match ($outcome) {
                EventStoreOutcome::Stored => $this->onStored($client, $event, $eventId, $kind),
                EventStoreOutcome::Duplicate => $this->onDuplicate($client, $event, $eventId),
                EventStoreOutcome::Superseded => $this->onSuperseded($client, $event, $eventId, $kind),
            };
        } catch (InvalidEventException $e) {
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage()));
            $this->logger->warning('Event invalid', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'reason' => $e->getMessage()]);
        } catch (AuthRequiredException) {
            if (null === $this->authManager->getChallenge($client->getId())) {
                $this->messenger->send($client, new AuthMessage($this->authManager->getOrCreateChallenge($client->getId())));
            }
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'auth-required: authentication required'));
            $this->logger->debug('Event auth-required', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
        } catch (PolicyViolationException $e) {
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'blocked: '.$e->getMessage()));
            $this->logger->warning('Event blocked', [
                'event_id' => $eventId,
                'pubkey' => $event->getPubkey()->toHex(),
                'kind' => $kind,
                'reason' => $e->getMessage(),
            ]);
        } catch (RateLimitException) {
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'rate-limited: slow down'));
            $this->logger->warning('Event rate-limited', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
        } catch (Throwable $e) {
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'error: could not process event'));
            $this->logger->error('Event processing error', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'error' => $e->getMessage()]);
        }
    }

    private function onStored(RelayClient $client, Event $event, string $eventId, int $kind): void
    {
        $this->metrics->incrementEventsReceived();
        $this->registry->recordEventAccepted($client->getId());

        if ($event->isDeletion()) {
            $this->deletionProcessor->process($event);
        }

        $this->deferredExecutor->defer(fn () => $this->distributor->distributeToSubscribers($event));

        $this->messenger->send($client, new OkMessage($event->getId(), true, ''));
        $this->logger->debug('Event stored', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'kind' => $kind]);
    }

    private function onDuplicate(RelayClient $client, Event $event, string $eventId): void
    {
        $this->messenger->send($client, new OkMessage($event->getId(), false, 'duplicate: event already exists'));
        $this->logger->debug('Event duplicate', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
    }

    private function onSuperseded(RelayClient $client, Event $event, string $eventId, int $kind): void
    {
        $this->messenger->send($client, new OkMessage($event->getId(), false, 'duplicate: newer version already exists'));
        $this->logger->debug('Event superseded', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'kind' => $kind]);
    }
}
