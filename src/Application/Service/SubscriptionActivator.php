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
        $admission = $this->admission->admitSubscription($client, $subscriptionId, $filters);

        if ($admission instanceof PolicyRejection) {
            return $this->subscriptionAnswers->settleRefusal($client, $subscriptionId, $admission);
        }

        $this->storedEventStreamer->stream($client, Subscription::create($subscriptionId, $admission->getFilters(), SubscriptionState::Active), $admission);

        return [];
    }
}
