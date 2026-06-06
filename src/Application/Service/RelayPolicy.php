<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class RelayPolicy implements RelayPolicyInterface
{
    private readonly array $tenantPubkeys;
    private readonly array $tenantHexKeys;
    private readonly array $guestReadKinds;
    private readonly bool $guestReadFromTenants;
    private readonly array $guestWriteRules;
    private readonly int $maxSubscriptions;
    private readonly int $maxEventSize;
    private readonly SubscriptionLimits $subscriptionLimits;
    private readonly GuestFilterRules $guestFilterRules;

    public function __construct(
        private readonly AuthenticationManager $authManager,
        private readonly LoggerInterface $logger,
        array $config = [],
    ) {
        $this->tenantPubkeys = $this->resolveTenants($config['tenants'] ?? []);
        $this->tenantHexKeys = array_map(static fn (PublicKey $pk) => $pk->toHex(), $this->tenantPubkeys);
        $this->maxSubscriptions = $config['max_subscriptions'] ?? 20;
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
            $this->maxSubscriptions,
            $config['max_filters'] ?? 5,
            $config['max_query_limit'] ?? 1000,
        );
        $this->guestFilterRules = new GuestFilterRules($this->tenantHexKeys, $this->guestReadKinds);
    }

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

    public function allowSubscription(RelayClient $client, array $filters): void
    {
        $this->subscriptionLimits->enforce($client, $filters);

        if ($this->isOpenRelay() || $this->isTenant($client)) {
            return;
        }

        if (!$this->isGuestAllowedSubscription($filters)) {
            $this->logger->info('Auth required for subscription', [
                'client_id' => (string) $client->getId(),
            ]);

            throw new AuthRequiredException('authentication required for this subscription');
        }
    }

    public function filterForClient(RelayClient $client, array $filters): array
    {
        if ($this->isOpenRelay() || $this->isTenant($client)) {
            return $filters;
        }

        return array_map(
            fn (Filter $filter): Filter => $this->guestFilterRules->injectReadableKinds(
                $this->guestFilterRules->constrainAuthorsToTenants($filter),
            ),
            $filters
        );
    }

    public function canClientReceiveEvent(RelayClient $client, Event $event): bool
    {
        if ($this->isOpenRelay() || $this->isTenant($client)) {
            return true;
        }

        if (!empty($this->guestReadKinds) && !in_array($event->getKind()->toInt(), $this->guestReadKinds, true)) {
            return false;
        }

        if ($this->guestReadFromTenants) {
            return $this->isTenantPubkey($event->getPubkey());
        }

        return true;
    }

    public function getMaxSubscriptionsPerClient(): int
    {
        return $this->maxSubscriptions;
    }

    public function isRateLimitExempt(RelayClient $client): bool
    {
        return $this->isOpenRelay() || $this->isTenant($client);
    }

    private function isOpenRelay(): bool
    {
        return empty($this->tenantPubkeys);
    }

    private function isTenant(RelayClient $client): bool
    {
        foreach ($this->tenantPubkeys as $tenantPk) {
            if ($this->authManager->isAuthenticatedAs($client->getId(), $tenantPk)) {
                return true;
            }
        }

        return false;
    }

    private function isTenantPubkey(PublicKey $pubkey): bool
    {
        return in_array($pubkey->toHex(), $this->tenantHexKeys, true);
    }

    private function isTaggedToTenant(Event $event): bool
    {
        return !empty(array_intersect($event->getTags()->getPubkeys(), $this->tenantHexKeys));
    }

    private function isGuestAllowedSubscription(array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($this->guestReadFromTenants && !$this->guestFilterRules->authorsWithinTenants($filter)) {
                return false;
            }

            if (!$this->guestFilterRules->kindsWithinReadable($filter)) {
                return false;
            }
        }

        return true;
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
