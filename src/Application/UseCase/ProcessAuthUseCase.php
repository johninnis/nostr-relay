<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\InvalidEventException;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticatedSessionsInterface;
use Innis\Nostr\Relay\Application\Service\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessAuthUseCase
{
    private const int TIMESTAMP_TOLERANCE_SECONDS = 600;

    public function __construct(
        private readonly AuthChallengeInterface $authChallenge,
        private readonly AuthenticatedSessionsInterface $authenticatedSessions,
        private readonly RelayConfigInterface $config,
        private readonly RelayPolicyInterface $policy,
        private readonly LoggerInterface $logger,
        private readonly EventValidatorInterface $eventValidator,
        private readonly ClientMessengerInterface $messenger,
        private readonly SubscriptionReevaluator $subscriptionReevaluator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(RelayClient $client, Event $event): void
    {
        try {
            $this->eventValidator->validateEvent($event);

            $challenge = $this->authChallenge->getChallenge($client->getId());
            if (null === $challenge) {
                $this->messenger->send($client, new AuthMessage($this->authChallenge->getOrCreateChallenge($client->getId())));
                $this->messenger->send($client, new OkMessage($event->getId(), false, 'auth-required: challenge issued, please retry'));

                return;
            }

            $challengeTags = $event->getTags()->getValuesByType(TagType::fromString('challenge'));
            if (empty($challengeTags) || reset($challengeTags) !== $challenge) {
                $this->messenger->send($client, new OkMessage($event->getId(), false, 'auth-required: invalid challenge'));

                return;
            }

            $relayTags = $event->getTags()->getValuesByType(TagType::fromString('relay'));
            $expectedRelayUrl = (string) $this->config->getRelayUrl();
            if (empty($relayTags) || reset($relayTags) !== $expectedRelayUrl) {
                $this->messenger->send($client, new OkMessage($event->getId(), false, 'auth-required: invalid relay URL'));

                return;
            }

            if ($this->clock->now()->differenceInSeconds($event->getCreatedAt()) > self::TIMESTAMP_TOLERANCE_SECONDS) {
                $this->messenger->send($client, new OkMessage($event->getId(), false, 'auth-required: timestamp out of range'));

                return;
            }

            if (!$this->policy->allowsAuthentication($event->getPubkey())) {
                $this->messenger->send($client, new OkMessage($event->getId(), false, 'restricted: authentication is limited to relay tenants'));

                return;
            }

            $this->authenticatedSessions->authenticate($client->getId(), $event->getPubkey());
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
