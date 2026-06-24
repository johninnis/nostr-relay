<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\ClientMessengerInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class CountSubscriptionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly SubscriptionAdmission $admission,
        private readonly ClientMessengerInterface $messenger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): void
    {
        // Deliberate: rejections are caught and framed as this message's wire reply here (CLOSED), not centralised in the router — see ADR-0003
        try {
            $scopedFilters = $this->admission->admit($client, $filters);

            $count = $this->eventStore->countByFilters($scopedFilters->getFilters());

            $this->messenger->send($client, new CountMessage($subscriptionId, $count));
        } catch (PolicyViolationException $e) {
            $this->messenger->send($client, new ClosedMessage($subscriptionId, 'blocked: '.$e->getMessage()));
        } catch (RateLimitException) {
            $this->messenger->send($client, new ClosedMessage($subscriptionId, 'rate-limited: slow down'));
        } catch (Throwable $e) {
            $this->messenger->send($client, new ClosedMessage($subscriptionId, 'error: could not count events'));
            $this->logger->error('Count subscription error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
