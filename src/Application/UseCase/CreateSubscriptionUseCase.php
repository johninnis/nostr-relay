<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionRegistryInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\RejectionReason;
use Psr\Log\LoggerInterface;
use Throwable;

final class CreateSubscriptionUseCase
{
    public function __construct(
        private readonly SubscriptionActivator $activator,
        private readonly SubscriptionRegistryInterface $subscriptionRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        // Deliberate: rejections are framed as this message's wire reply here (CLOSED), not centralised in the router — see ADR-0003
        try {
            return $this->activator->activate($client, $subscriptionId, $filters);
        } catch (Throwable $e) {
            $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);
            $this->logger->error('Subscription creation error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return [new ClosedMessage($subscriptionId, RejectionReason::Error->format('invalid subscription'))];
        }
    }
}
