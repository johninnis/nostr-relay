<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ProcessAuth;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidationServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProcessAuthUseCase
{
    private const int TIMESTAMP_TOLERANCE_SECONDS = 600;

    public function __construct(
        private readonly AuthenticationManager $authManager,
        private readonly RelayConfigInterface $config,
        private readonly RelayPolicyInterface $policy,
        private readonly LoggerInterface $logger,
        private readonly EventValidationServiceInterface $eventValidator,
    ) {
    }

    public function execute(RelayClient $client, Event $event): void
    {
        try {
            $this->eventValidator->validateEvent($event);

            $challenge = $this->authManager->getChallenge($client->getId());
            if (null === $challenge) {
                $this->authManager->challenge($client);
                $client->send(new OkMessage($event->getId(), false, 'auth-required: challenge issued, please retry'));

                return;
            }

            $challengeTags = $event->getTags()->getValuesByType(TagType::fromString('challenge'));
            if (empty($challengeTags) || reset($challengeTags) !== $challenge) {
                $client->send(new OkMessage($event->getId(), false, 'auth-required: invalid challenge'));

                return;
            }

            $relayTags = $event->getTags()->getValuesByType(TagType::fromString('relay'));
            $expectedRelayUrl = (string) $this->config->getRelayUrl();
            if (empty($relayTags) || reset($relayTags) !== $expectedRelayUrl) {
                $client->send(new OkMessage($event->getId(), false, 'auth-required: invalid relay URL'));

                return;
            }

            $now = time();
            $eventTime = $event->getCreatedAt()->toInt();
            if (abs($now - $eventTime) > self::TIMESTAMP_TOLERANCE_SECONDS) {
                $client->send(new OkMessage($event->getId(), false, 'auth-required: timestamp out of range'));

                return;
            }

            if (!$this->policy->allowsAuthentication($event->getPubkey())) {
                $client->send(new OkMessage($event->getId(), false, 'restricted: authentication is limited to relay tenants'));

                return;
            }

            $this->authManager->authenticate($client->getId(), $event->getPubkey());
            $client->send(new OkMessage($event->getId(), true, ''));

            $this->logger->info('Client authenticated', [
                'client_id' => (string) $client->getId(),
                'pubkey' => $event->getPubkey()->toHex(),
            ]);
        } catch (Throwable $e) {
            $client->send(new OkMessage($event->getId(), false, 'auth-required: '.$e->getMessage()));
            $this->logger->warning('AUTH validation failed', [
                'client_id' => (string) $client->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
