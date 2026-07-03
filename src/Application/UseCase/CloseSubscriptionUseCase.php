<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Service\SubscriptionRegistryInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class CloseSubscriptionUseCase
{
    public function __construct(
        private readonly SubscriptionRegistryInterface $subscriptionRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, SubscriptionId $subscriptionId): array
    {
        try {
            $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);
        } catch (Throwable $e) {
            $this->logger->error('Subscription close error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }
}
