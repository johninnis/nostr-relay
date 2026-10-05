<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

final readonly class SubscriptionActivator
{
    public function __construct(
        private SubscriptionAdmission $admission,
        private RegisteringStoredEventStreamer $storedEventStreamer,
        private SubscriptionAnswers $subscriptionAnswers,
    ) {
    }

    /**
     * @return list<RelayMessage>
     */
    public function activate(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        $admission = $this->admission->admit($client, $filters);

        if ($admission instanceof PolicyRejection) {
            return $this->subscriptionAnswers->settleRefusal($client, $subscriptionId, $admission);
        }

        return $this->register($client, $subscriptionId, $admission);
    }

    /**
     * @return list<RelayMessage>
     */
    public function reactivate(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        $admission = $this->admission->readmit($client, $filters);

        if ($admission instanceof PolicyRejection) {
            return $this->subscriptionAnswers->settleRefusal($client, $subscriptionId, $admission);
        }

        return $this->register($client, $subscriptionId, $admission);
    }

    /**
     * @return list<RelayMessage>
     */
    private function register(RelayClient $client, SubscriptionId $subscriptionId, ScopedFilters $admission): array
    {
        $subscription = Subscription::create($subscriptionId, $admission->getFilters(), SubscriptionState::Active);

        $this->storedEventStreamer->stream($client, $subscription, $admission);

        return $this->subscriptionAnswers->forScope($client, $admission);
    }
}
