<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\RejectionReason;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Throwable;

final class CountSubscriptionUseCase
{
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly SubscriptionAdmission $admission,
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
            $scopedFilters = $this->admission->admit($client, $filters);

            return [new CountMessage($subscriptionId, $this->eventStore->countByFilters($scopedFilters->getFilters()))];
        } catch (PolicyViolationException $e) {
            return [new ClosedMessage($subscriptionId, RejectionReason::Blocked->format($e->getMessage()))];
        } catch (RateLimitException) {
            return [new ClosedMessage($subscriptionId, RejectionReason::RateLimited->format('slow down'))];
        } catch (Throwable $e) {
            $this->logger->error('Count subscription error', [
                'client_id' => (string) $client->getId(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return [new ClosedMessage($subscriptionId, RejectionReason::Error->format('could not count events'))];
        }
    }
}
