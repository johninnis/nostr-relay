<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\AuthenticationRegistryInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessAuthUseCase
{
    // Deliberate: NIP-42 auth orchestration — coordinates the auth registry, pure verifier, event validator, subscription re-evaluation and logging; the pure decision is extracted to AuthEventVerifier, replies are returned for the caller to transmit, and the residual collaborators are irreducible side-effect ports.
    public function __construct(
        private readonly AuthenticationRegistryInterface $authRegistry,
        private readonly AuthEventVerifier $verifier,
        private readonly EventValidatorInterface $eventValidator,
        private readonly SubscriptionReevaluator $subscriptionReevaluator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, Event $event): array
    {
        try {
            $this->eventValidator->validateEvent($event);

            $challenge = $this->authRegistry->getChallenge($client->getId());
            if (null === $challenge) {
                return [
                    new AuthMessage($this->authRegistry->getOrCreateChallenge($client->getId())),
                    new OkMessage($event->getId(), false, 'auth-required: challenge issued, please retry'),
                ];
            }

            $rejection = $this->verifier->verify($event, $challenge);
            if (null !== $rejection) {
                return [new OkMessage($event->getId(), false, $rejection->value)];
            }

            $this->authRegistry->authenticate($client->getId(), $event->getPubkey());
            $this->subscriptionReevaluator->reevaluate($client);

            $this->logger->info('Client authenticated', [
                'client_id' => (string) $client->getId(),
                'pubkey' => $event->getPubkey()->toHex(),
            ]);

            return [new OkMessage($event->getId(), true, '')];
        } catch (InvalidEventException $e) {
            $this->logger->warning('AUTH event validation failed', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);

            return [new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage())];
        } catch (Throwable $e) {
            $this->logger->error('AUTH processing error', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);

            return [new OkMessage($event->getId(), false, 'error: could not process authentication')];
        }
    }
}
