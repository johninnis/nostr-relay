<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase\ManageSubscription;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class CountSubscriptionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly AuthenticationManager $authManager,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(RelayClient $client, SubscriptionId $subscriptionId, array $filters): void
    {
        try {
            $this->rateLimiter->checkLimit($client->getConnectionInfo()->getIpAddress());

            $this->policy->allowSubscription($client, $filters);

            $modifiedFilters = $this->policy->filterForClient($client, $filters);

            $count = $this->eventStore->countByFilters($modifiedFilters);

            $client->send(new CountMessage($subscriptionId, $count));
        } catch (AuthRequiredException) {
            $challenge = $this->authManager->generateChallenge($client->getId());
            $client->send(new AuthMessage($challenge));
            $client->send(new ClosedMessage($subscriptionId, 'auth-required: authentication required'));
        } catch (PolicyViolationException $e) {
            $client->send(new ClosedMessage($subscriptionId, 'blocked: '.$e->getMessage()));
        } catch (RateLimitException) {
            $client->send(new ClosedMessage($subscriptionId, 'rate-limited: slow down'));
        } catch (Throwable $e) {
            $client->send(new ClosedMessage($subscriptionId, 'error: could not count events'));
            $this->logger->error('Count subscription error', [
                'client_id' => $client->getId()->toString(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
