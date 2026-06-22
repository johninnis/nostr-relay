<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;

final class RelayPolicy implements RelayPolicyInterface
{
    private readonly array $tenantPubkeys;
    private readonly array $tenantHexSet;
    private readonly array $guestReadKinds;
    private readonly bool $guestReadFromTenants;
    private readonly array $guestWriteRules;
    private readonly int $maxEventSize;
    private readonly SubscriptionLimits $subscriptionLimits;
    private readonly GuestFilterRules $guestFilterRules;

    public function __construct(
        private readonly AuthenticationManager $authManager,
        private readonly LoggerInterface $logger,
        array $config = [],
    ) {
        $this->tenantPubkeys = $this->resolveTenants($config['tenants'] ?? []);
        $tenantHexKeys = array_map(static fn (PublicKey $pk) => $pk->toHex(), $this->tenantPubkeys);
        $this->tenantHexSet = array_flip($tenantHexKeys);
        $this->maxEventSize = $config['max_event_size'] ?? 65536;

        $guest = $config['guest'] ?? [];
        $this->guestWriteRules = $this->resolveWriteRules($guest['write'] ?? []);

        $allKinds = [];
        $fromTenants = false;
        foreach ($guest['read'] ?? [] as $rule) {
            foreach ($rule['kinds'] ?? [] as $kind) {
                $allKinds[] = $kind;
            }
            if (($rule['from'] ?? null) === 'tenants') {
                $fromTenants = true;
            }
        }
        $this->guestReadKinds = array_values(array_unique($allKinds));
        $this->guestReadFromTenants = $fromTenants;

        $this->subscriptionLimits = new SubscriptionLimits(
            $config['max_subscriptions'] ?? 20,
            $config['max_filters'] ?? 5,
            $config['max_query_limit'] ?? 1000,
        );
        $this->guestFilterRules = new GuestFilterRules($tenantHexKeys, $this->guestReadKinds);
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

        $kind = $event->getKind()->toInt();

        foreach ($this->guestWriteRules as $rule) {
            if (!in_array($kind, $rule['kinds'], true)) {
                continue;
            }

            if ($rule['tagged_to_tenant'] && !$this->isTaggedToTenant($event)) {
                throw new PolicyViolationException('event must be tagged to a relay tenant');
            }

            return;
        }

        $this->logger->info('Auth required for event submission', [
            'client_id' => (string) $client->getId(),
            'kind' => $kind,
        ]);

        throw new AuthRequiredException('authentication required to publish this event kind');
    }

    #[Override]
    public function allowSubscription(RelayClient $client, FilterCollection $filters, int $currentSubscriptionCount): void
    {
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
        return $this->isOpenRelay() || $this->isTenant($client);
    }

    #[Override]
    public function allowsAuthentication(PublicKey $pubkey): bool
    {
        return $this->isOpenRelay() || $this->isTenantPubkey($pubkey);
    }

    private function isOpenRelay(): bool
    {
        return empty($this->tenantPubkeys);
    }

    private function isTenant(RelayClient $client): bool
    {
        foreach ($this->authManager->getAuthenticatedPubkeys($client->getId()) as $pubkey) {
            if (isset($this->tenantHexSet[$pubkey->toHex()])) {
                return true;
            }
        }

        return false;
    }

    private function isTenantPubkey(PublicKey $pubkey): bool
    {
        return isset($this->tenantHexSet[$pubkey->toHex()]);
    }

    private function isTaggedToTenant(Event $event): bool
    {
        foreach ($event->getTags()->getPubkeys() as $taggedPubkey) {
            if (isset($this->tenantHexSet[$taggedPubkey])) {
                return true;
            }
        }

        return false;
    }

    private function resolveTenants(array $tenants): array
    {
        $pubkeys = [];

        foreach ($tenants as $tenant) {
            $pubkey = str_starts_with($tenant, 'npub')
                ? PublicKey::fromBech32($tenant)
                : PublicKey::fromHex($tenant);

            if (null === $pubkey) {
                throw new InvalidArgumentException(sprintf('Invalid tenant pubkey: %s', $tenant));
            }

            $pubkeys[] = $pubkey;
        }

        return $pubkeys;
    }

    private function resolveWriteRules(array $rules): array
    {
        return array_map(static fn (array $rule) => [
            'kinds' => $rule['kinds'] ?? [],
            'tagged_to_tenant' => $rule['tagged_to_tenant'] ?? false,
        ], $rules);
    }
}
