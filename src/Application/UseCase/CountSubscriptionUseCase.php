<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Enum\RejectionReason;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Psr\Log\LoggerInterface;
use Throwable;

final class CountSubscriptionUseCase
{
    // Deliberate: count orchestration coordinates the store, admission, challenge issuer and logging — see ADR-0010
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly SubscriptionAdmission $admission,
        private readonly AuthChallengeIssuer $authChallengeIssuer,
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
            $admission = $this->admission->admit($client, $filters);

            if ($admission instanceof PolicyRejection) {
                return [new ClosedMessage($subscriptionId, $admission->toWireReason())];
            }

            // Deliberate: the AUTH challenge is offered lazily on a scope-exceeding request, never on connect — see ADR-0004
            $replies = $this->authChallengeIssuer->offerForScope($admission, $client->getId());
            $replies[] = new CountMessage($subscriptionId, $this->eventStore->countByFilters($admission->getFilters()));

            return $replies;
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
