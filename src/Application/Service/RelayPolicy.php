<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Collection\GuestWriteRuleCollection;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Override;
use Psr\Log\LoggerInterface;

final class RelayPolicy implements RelayPolicyInterface
{
    private readonly PublicKeyCollection $tenants;
    private readonly bool $guestReadFromTenants;
    private readonly GuestWriteRuleCollection $guestWriteRules;
    private readonly int $maxEventSize;
    private readonly SubscriptionLimits $subscriptionLimits;
    private readonly GuestFilterRules $guestFilterRules;

    public function __construct(
        private readonly AuthenticatedSessionsInterface $authenticatedSessions,
        private readonly LoggerInterface $logger,
        RelayPolicyConfig $config,
    ) {
        $guest = $config->getGuest();
        $this->tenants = $config->getTenants();
        $this->maxEventSize = $config->getMaxEventSize();
        $this->subscriptionLimits = $config->getSubscriptionLimits();
        $this->guestReadFromTenants = $guest->readsFromTenantsOnly();
        $this->guestWriteRules = $guest->getWriteRules();
        $this->guestFilterRules = new GuestFilterRules($config->getTenants(), $guest->getReadableKinds(), new EventKindCollection([]));
    }

    #[Override]
    public function allowEventSubmission(RelayClient $client, Event $event): void
    {
        if ($event->getContent()->getLength() > $this->maxEventSize) {
            throw new PolicyViolationException('event too large');
        }

        if ($this->isOpenRelay() || $this->isTenant($client)) {
            return;
        }

        $kind = $event->getKind();

        foreach ($this->guestWriteRules as $rule) {
            if (!$rule->appliesToKind($kind)) {
                continue;
            }

            if ($rule->requiresTenantTag() && !$this->isTaggedToTenant($event)) {
                throw new PolicyViolationException('event must be tagged to a relay tenant');
            }

            return;
        }

        $this->logger->info('Auth required for event submission', [
            'client_id' => (string) $client->getId(),
            'kind' => $kind->toInt(),
        ]);

        throw new AuthRequiredException('authentication required to publish this event kind');
    }

    #[Override]
    public function allowSubscription(RelayClient $client, FilterCollection $filters, int $currentSubscriptionCount): void
    {
        // Deliberate: resource caps protect the store and apply to every untrusted client, even on an open relay; only a tenant is exempt — see ADR-0009
        if ($this->isTenant($client)) {
            return;
        }

        $this->subscriptionLimits->enforce($currentSubscriptionCount, $filters);
    }

    #[Override]
    public function filterForClient(RelayClient $client, FilterCollection $filters): ScopedFilters
    {
        if ($this->isOpenRelay() || $this->isTenant($client)) {
            return ScopedFilters::unchanged($filters);
        }

        return $this->guestFilterRules->scope($filters, $this->guestReadFromTenants);
    }

    #[Override]
    public function canClientReceiveEvent(RelayClient $client, Event $event): bool
    {
        if ($this->isOpenRelay() || $this->isTenant($client)) {
            return true;
        }

        return $this->guestFilterRules->allowsEvent($event, $this->guestReadFromTenants);
    }

    #[Override]
    public function isRateLimitExempt(RelayClient $client): bool
    {
        // Deliberate: rate limiting protects the host and applies to every untrusted client, even on an open relay; only a tenant is exempt — see ADR-0009
        return $this->isTenant($client);
    }

    #[Override]
    public function allowsAuthentication(PublicKey $pubkey): bool
    {
        return $this->isOpenRelay() || $this->isTenantPubkey($pubkey);
    }

    private function isOpenRelay(): bool
    {
        return $this->tenants->isEmpty();
    }

    private function isTenant(RelayClient $client): bool
    {
        foreach ($this->authenticatedSessions->getAuthenticatedPubkeys($client->getId()) as $pubkey) {
            if ($this->tenants->contains($pubkey)) {
                return true;
            }
        }

        return false;
    }

    private function isTenantPubkey(PublicKey $pubkey): bool
    {
        return $this->tenants->contains($pubkey);
    }

    private function isTaggedToTenant(Event $event): bool
    {
        foreach ($event->getTags()->getPubkeys() as $taggedPubkey) {
            if ($this->isTenantPubkey($taggedPubkey)) {
                return true;
            }
        }

        return false;
    }
}
