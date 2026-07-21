<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\AuthenticationRegistryInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\RejectionReason;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessAuthUseCase
{
    // Deliberate: NIP-42 auth orchestration coordinates distinct collaborators — see ADR-0010
    public function __construct(
        private readonly AuthenticationRegistryInterface $authRegistry,
        private readonly AuthEventVerifier $verifier,
        private readonly EventValidatorInterface $eventValidator,
        private readonly SubscriptionReevaluator $subscriptionReevaluator,
        private readonly AuthChallengeIssuer $authChallengeIssuer,
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
                    $this->authChallengeIssuer->issue($client->getId()),
                    new OkMessage($event->getId(), false, RejectionReason::AuthRequired->format('challenge issued, please retry')),
                ];
            }

            $rejection = $this->verifier->verify($event, $challenge);
            if (null !== $rejection) {
                return [new OkMessage($event->getId(), false, $rejection->toWireReason())];
            }

            $this->authRegistry->authenticate($client->getId(), $event->getPubkey());
            $reevaluationReplies = $this->subscriptionReevaluator->reevaluate($client);

            $this->logger->info('Client authenticated', [
                'client_id' => (string) $client->getId(),
                'pubkey' => $event->getPubkey()->toHex(),
            ]);

            return [...$reevaluationReplies, new OkMessage($event->getId(), true, '')];
        } catch (InvalidEventException $e) {
            $this->logger->warning('AUTH event validation failed', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);

            return [new OkMessage($event->getId(), false, RejectionReason::Invalid->format($e->getMessage()))];
        } catch (Throwable $e) {
            $this->logger->error('AUTH processing error', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);

            return [new OkMessage($event->getId(), false, RejectionReason::Error->format('could not process authentication'))];
        }
    }
}
