<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\AuthRequiredException;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Psr\Log\LoggerInterface;

final class RelayPolicy implements RelayPolicyInterface
{
    private readonly array $tenantPubkeys;
    private readonly array $tenantHexKeys;
    private readonly array $guestReadKinds;
    private readonly bool $guestReadFromTenants;
    private readonly array $guestWriteRules;
    private readonly int $maxSubscriptions;
    private readonly int $maxFilters;
    private readonly int $maxEventSize;
    private readonly int $maxQueryLimit;

    public function __construct(
        private readonly AuthenticationManager $authManager,
        private readonly LoggerInterface $logger,
        array $config = [],
    ) {
        $this->tenantPubkeys = $this->resolveTenants($config['tenants'] ?? []);
        $this->tenantHexKeys = array_map(static fn (PublicKey $pk) => $pk->toHex(), $this->tenantPubkeys);
        $this->maxSubscriptions = $config['max_subscriptions'] ?? 20;
        $this->maxFilters = $config['max_filters'] ?? 5;
        $this->maxEventSize = $config['max_event_size'] ?? 65536;
        $this->maxQueryLimit = $config['max_query_limit'] ?? 1000;

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
        if ($client->getSubscriptionCount() >= $this->maxSubscriptions) {
            throw new PolicyViolationException('too many subscriptions (max '.$this->maxSubscriptions.')');
        }

        if (count($filters) > $this->maxFilters) {
            throw new PolicyViolationException('too many filters (max '.$this->maxFilters.')');
        }

        foreach ($filters as $filter) {
            if ($filter->hasLimit() && $filter->getLimit() > $this->maxQueryLimit) {
                throw new PolicyViolationException('filter limit too high (max '.$this->maxQueryLimit.')');
            }
        }

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
            function (Filter $filter) {
                if ($filter->hasAuthors()) {
                    $allowed = array_intersect($filter->getAuthors() ?? [], $this->tenantHexKeys);
                    $constrained = $filter->withAuthors(array_values($allowed));
                } else {
                    $constrained = $filter->withAuthors($this->tenantHexKeys);
                }

                if (!$filter->hasKinds() && !empty($this->guestReadKinds)) {
                    $constrained = $constrained->withKinds($this->guestReadKinds);
                }

                return $constrained;
            },
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
            if ($filter->hasAuthors() && $this->guestReadFromTenants) {
                $requested = $filter->getAuthors() ?? [];
                if (!empty(array_diff($requested, $this->tenantHexKeys))) {
                    return false;
                }
            }

            if (!empty($this->guestReadKinds) && $filter->hasKinds()) {
                foreach ($filter->getKinds() as $kind) {
                    $kindInt = $kind instanceof EventKind ? $kind->toInt() : $kind;
                    if (!in_array($kindInt, $this->guestReadKinds, true)) {
                        return false;
                    }
                }
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

            if (null !== $pubkey) {
                $pubkeys[] = $pubkey;
            }
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
