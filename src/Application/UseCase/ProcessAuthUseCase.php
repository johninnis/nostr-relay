<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Relay\Application\Service\AuthenticationRegistryInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessAuthUseCase
{
    // Deliberate: NIP-42 auth orchestration — coordinates the auth registry, pure verifier, event validator, client reply, subscription re-evaluation and logging; the pure decision is extracted to AuthEventVerifier and the residual collaborators are irreducible side-effect ports.
    public function __construct(
        private readonly AuthenticationRegistryInterface $authRegistry,
        private readonly AuthEventVerifier $verifier,
        private readonly EventValidatorInterface $eventValidator,
        private readonly ClientMessengerInterface $messenger,
        private readonly SubscriptionReevaluator $subscriptionReevaluator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(RelayClient $client, Event $event): void
    {
        try {
            $this->eventValidator->validateEvent($event);

            $challenge = $this->authRegistry->getChallenge($client->getId());
            if (null === $challenge) {
                $this->messenger->send($client, new AuthMessage($this->authRegistry->getOrCreateChallenge($client->getId())));
                $this->messenger->send($client, new OkMessage($event->getId(), false, 'auth-required: challenge issued, please retry'));

                return;
            }

            $rejection = $this->verifier->verify($event, $challenge);
            if (null !== $rejection) {
                $this->messenger->send($client, new OkMessage($event->getId(), false, $rejection->value));

                return;
            }

            $this->authRegistry->authenticate($client->getId(), $event->getPubkey());
            $this->messenger->send($client, new OkMessage($event->getId(), true, ''));

            $this->subscriptionReevaluator->reevaluate($client);

            $this->logger->info('Client authenticated', [
                'client_id' => (string) $client->getId(),
                'pubkey' => $event->getPubkey()->toHex(),
            ]);
        } catch (InvalidEventException $e) {
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'invalid: '.$e->getMessage()));
            $this->logger->warning('AUTH event validation failed', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->messenger->send($client, new OkMessage($event->getId(), false, 'error: could not process authentication'));
            $this->logger->error('AUTH processing error', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
