<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidationServiceInterface;
use Innis\Nostr\Core\Domain\Service\TagReferenceExtractor;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinate;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

final class ProcessEventSubmissionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly EventDistributor $distributor,
        private readonly AuthenticationManager $authManager,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
        private readonly EventValidationServiceInterface $eventValidator,
    ) {
    }

    private function processDeletion(Event $event): void
    {
        $references = TagReferenceExtractor::extract($event->getTags());
        $author = $event->getPubkey();
        $deletedCount = 0;

        $requestedEventIds = array_map(
            static fn ($ref) => $ref->getEventId(),
            $references->getEvents()
        );
        $requestedCoordinates = $references->getAddressable();
        $requestedCount = count($requestedEventIds) + count($requestedCoordinates);

        $verifiedEventIds = $this->verifyOwnedEventIds($requestedEventIds, $author);
        $verifiedCoordinates = array_values(array_filter(
            $requestedCoordinates,
            static fn (EventCoordinate $coord) => $coord->getPubkey()->equals($author)
        ));
        $verifiedCount = count($verifiedEventIds) + count($verifiedCoordinates);

        if ($verifiedCount < $requestedCount) {
            $this->logger->warning('Deletion event referenced events not owned by author', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'requested' => $requestedCount,
                'verified' => $verifiedCount,
            ]);
        }

        if (!empty($verifiedEventIds)) {
            $deletedCount += $this->eventStore->deleteByEventIds($verifiedEventIds, $author);
        }

        if (!empty($verifiedCoordinates)) {
            $deletedCount += $this->eventStore->deleteByCoordinates($verifiedCoordinates, $author);
        }

        if ($deletedCount > 0) {
            $this->logger->debug('Deletion event processed', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'referenced' => $requestedCount,
                'deleted_count' => $deletedCount,
            ]);
        } elseif ($requestedCount > 0) {
            $this->logger->debug('Deletion event had no effect', [
                'deletion_event_id' => $event->getId()->toHex(),
                'pubkey' => $author->toHex(),
                'referenced' => $requestedCount,
            ]);
        }
    }

    private function verifyOwnedEventIds(array $eventIds, PublicKey $author): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $filters = array_map(
            static fn (array $chunk) => new Filter(ids: array_map(
                static fn (EventId $id) => $id->toHex(),
                $chunk
            )),
            array_chunk($eventIds, Filter::MAX_VALUES_PER_FIELD)
        );

        $storedEvents = $this->eventStore->findByFilters($filters, count($eventIds));

        $verified = [];
        foreach ($storedEvents as $storedEvent) {
            if ($storedEvent->getPubkey()->equals($author)) {
                $verified[] = $storedEvent->getId();
            }
        }

        return $verified;
    }

    public function execute(RelayClient $client, Event $event): void
    {
        $eventId = $event->getId()->toHex();
        $kind = $event->getKind()->toInt();
        $clientId = (string) $client->getId();

        $client->recordEventReceived();

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
                $client->recordEventAccepted();
                async(fn () => $this->distributor->distributeToSubscribers($event));
                $client->send(new OkMessage($event->getId(), true, ''));
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
            $client->send(new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage()));
            $this->logger->warning('Event invalid', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'reason' => $e->getMessage()]);
        } catch (AuthRequiredException) {
            $this->authManager->challenge($client);
            $client->send(new OkMessage($event->getId(), false, 'auth-required: authentication required'));
            $this->logger->debug('Event auth-required', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
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

    private function onStored(RelayClient $client, Event $event, string $eventId, int $kind): void
    {
        $this->metrics->incrementEventsReceived();
        $client->recordEventAccepted();

        if ($event->isDeletion()) {
            $this->processDeletion($event);
        }

        async(fn () => $this->distributor->distributeToSubscribers($event));

        $client->send(new OkMessage($event->getId(), true, ''));
        $this->logger->debug('Event stored', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'kind' => $kind]);
    }

    private function onDuplicate(RelayClient $client, Event $event, string $eventId): void
    {
        $client->send(new OkMessage($event->getId(), false, 'duplicate: event already exists'));
        $this->logger->debug('Event duplicate', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex()]);
    }

    private function onSuperseded(RelayClient $client, Event $event, string $eventId, int $kind): void
    {
        $client->send(new OkMessage($event->getId(), false, 'duplicate: newer version already exists'));
        $this->logger->debug('Event superseded', ['event_id' => $eventId, 'pubkey' => $event->getPubkey()->toHex(), 'kind' => $kind]);
    }
}
