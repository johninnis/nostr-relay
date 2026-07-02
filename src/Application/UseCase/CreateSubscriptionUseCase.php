<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionRegistryInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class CreateSubscriptionUseCase
{
    // Deliberate: subscription-creation orchestration coordinates distinct collaborators — see ADR-0010
    public function __construct(
        private readonly SubscriptionAdmission $admission,
        private readonly SubscriptionRegistryInterface $subscriptionManager,
        private readonly StoredEventStreamer $storedEventStreamer,
        private readonly DeferredExecutorInterface $deferredExecutor,
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
            $modifiedFilters = $this->admission->admit($client, $filters)->getFilters();

            $subscription = Subscription::create($subscriptionId, $modifiedFilters, SubscriptionState::Active);

            $this->subscriptionManager->addSubscription($client->getId(), $subscription, $filters);

            $this->deferredExecutor->defer(fn () => $this->storedEventStreamer->stream($client, $subscription, $modifiedFilters));

            return [];
        } catch (PolicyViolationException $e) {
            $this->logger->warning('Subscription rejected by policy', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'reason' => $e->getMessage(),
                'filters' => $filters->toJsonArray(),
            ]);

            return [new ClosedMessage($subscriptionId, 'blocked: '.$e->getMessage())];
        } catch (RateLimitException) {
            return [new ClosedMessage($subscriptionId, 'rate-limited: slow down')];
        } catch (Throwable $e) {
            $this->subscriptionManager->removeSubscription($client->getId(), $subscriptionId);
            $this->logger->error('Subscription creation error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return [new ClosedMessage($subscriptionId, 'error: invalid subscription')];
        }
    }
}
