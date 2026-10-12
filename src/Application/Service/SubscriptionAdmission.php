<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class SubscriptionAdmission
{
    public function __construct(
        private RateLimitGate $rateLimitGate,
        private ReadScopeGate $readScopeGate,
        private SubscriptionLookupInterface $subscriptionLookup,
    ) {
    }

    // Deliberate: a REQ on an id the client holds replaces that subscription, so it counts against the cap once, as the one it replaces — see ADR-0033
    public function admitSubscription(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): PolicyRejection|AdmittedRead
    {
        $heldBeside = count($this->subscriptionLookup->getSubscriptionsForClient($client->getId())->remove($subscriptionId));
        $admission = $this->rateLimitGate->admit($client) ?? $this->readScopeGate->admit($client, $filters, $heldBeside);

        // Deliberate: a read narrowed to nothing is refused auth-required rather than served empty, as NIP-42 shows — see ADR-0004
        return $admission instanceof AdmittedRead && $admission->isWhollyBeyondScope()
            ? PolicyRejection::authRequired('authentication required to read what this subscription asks for')
            : $admission;
    }

    public function admitCount(RelayClient $client, FilterCollection $filters): PolicyRejection|AdmittedRead
    {
        return $this->rateLimitGate->admit($client)
            ?? $this->readScopeGate->admit($client, $filters, $this->subscriptionLookup->getSubscriptionCountForClient($client->getId()));
    }
}
