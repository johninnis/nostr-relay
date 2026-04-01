<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ManageSubscription;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class CloseSubscriptionUseCase
{
    public function __construct(
        private readonly SubscriptionManager $subscriptionManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(RelayClient $client, SubscriptionId $subscriptionId): void
    {
        try {
            $this->subscriptionManager->removeSubscription($client->getId(), $subscriptionId);
        } catch (Throwable $e) {
            $this->logger->error('Subscription close error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
