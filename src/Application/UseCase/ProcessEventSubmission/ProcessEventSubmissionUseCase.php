<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidationService;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
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
        private readonly RateLimiterInterface $rateLimiter,
        private readonly MetricsCollectorInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
        $this->eventValidator = new EventValidationService();
    }

    public function execute(RelayClient $client, Event $event): void
    {
        try {
            $this->rateLimiter->checkLimit($client->getConnectionInfo()->getIpAddress());

            $this->eventValidator->validateEvent($event);

            $this->policy->allowEventSubmission($client, $event);

            $stored = $this->eventStore->store($event);

            if ($stored) {
                $this->metrics->incrementEventsReceived();

                async(fn () => $this->distributor->distributeToSubscribers($event));

                $client->send(new OkMessage($event->getId(), true, ''));

                $this->logger->debug('Event stored and distributed', [
                    'event_id' => $event->getId()->toHex(),
                    'kind' => $event->getKind()->toInt(),
                    'client_id' => $client->getId()->toString(),
                ]);
            } else {
                $client->send(new OkMessage($event->getId(), false, 'duplicate: event already exists'));
            }
        } catch (InvalidEventException $e) {
            $client->send(new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage()));
            $this->logger->warning('Event failed validation', [
                'client_id' => $client->getId()->toString(),
                'reason' => $e->getMessage(),
            ]);
        } catch (PolicyViolationException $e) {
            $client->send(new OkMessage($event->getId(), false, 'blocked: '.$e->getMessage()));
            $this->logger->warning('Event rejected by policy', [
                'client_id' => $client->getId()->toString(),
                'reason' => $e->getMessage(),
            ]);
        } catch (RateLimitException $e) {
            $client->send(new OkMessage($event->getId(), false, 'rate-limited: slow down'));
            $this->logger->warning('Event rate limited', [
                'client_id' => $client->getId()->toString(),
            ]);
        } catch (Throwable $e) {
            $client->send(new OkMessage($event->getId(), false, 'error: could not process event'));
            $this->logger->error('Event processing error', [
                'client_id' => $client->getId()->toString(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
