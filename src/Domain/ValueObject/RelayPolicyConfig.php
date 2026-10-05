<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\Collection\GuestWriteRuleCollection;

final readonly class RelayPolicyConfig
{
    private const int DEFAULT_MAX_SUBSCRIPTIONS = 20;
    private const int DEFAULT_MAX_FILTERS = 5;
    private const int DEFAULT_MAX_QUERY_LIMIT = 1000;
    private const int DEFAULT_MAX_FILTER_VALUES = 5000;

    public function __construct(
        private PublicKeyCollection $tenants,
        private GuestPolicy $guest,
        private SubscriptionLimits $subscriptionLimits,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function tryFromArray(array $config): ?self
    {
        $tenants = self::resolveTenants($config['tenants'] ?? null);

        if (null === $tenants) {
            return null;
        }

        $guest = self::asArray($config['guest'] ?? null);
        $readRules = self::listOfArrays($guest['read'] ?? null);
        $queryLimit = self::intOr($config['max_query_limit'] ?? null, self::DEFAULT_MAX_QUERY_LIMIT);
        $filterValues = self::intOr($config['max_filter_values'] ?? null, self::DEFAULT_MAX_FILTER_VALUES);

        if (!SubscriptionLimits::isCeilingInRange($queryLimit) || !SubscriptionLimits::isCeilingInRange($filterValues)) {
            return null;
        }

        return new self(
            $tenants,
            new GuestPolicy(
                self::resolveReadableKinds($guest['read'] ?? null),
                array_any($readRules, static fn (array $rule): bool => 'tenants' === ($rule['from'] ?? null)),
                self::resolveWriteRules($guest['write'] ?? null),
            ),
            new SubscriptionLimits(
                self::intOr($config['max_subscriptions'] ?? null, self::DEFAULT_MAX_SUBSCRIPTIONS),
                self::intOr($config['max_filters'] ?? null, self::DEFAULT_MAX_FILTERS),
                $queryLimit,
                $filterValues,
            ),
        );
    }

    public function getTenants(): PublicKeyCollection
    {
        return $this->tenants;
    }

    public function getGuest(): GuestPolicy
    {
        return $this->guest;
    }

    public function getSubscriptionLimits(): SubscriptionLimits
    {
        return $this->subscriptionLimits;
    }

    private static function resolveTenants(mixed $tenants): ?PublicKeyCollection
    {
        $pubkeys = [];

        foreach (self::asArray($tenants) as $tenant) {
            if (!is_string($tenant)) {
                return null;
            }

            $pubkey = PublicKey::tryFromNpubOrHex($tenant);

            if (null === $pubkey) {
                return null;
            }

            $pubkeys[] = $pubkey;
        }

        return new PublicKeyCollection($pubkeys);
    }

    // Deliberate: only an absent read section is unrestricted, a present one reads exactly what it lists — see ADR-0012
    private static function resolveReadableKinds(mixed $readSection): ?EventKindCollection
    {
        if (null === $readSection) {
            return null;
        }

        return EventKindCollection::fromInts(array_merge(...array_map(
            static fn (array $rule): array => self::asList($rule['kinds'] ?? null),
            self::listOfArrays($readSection),
        )));
    }

    private static function resolveWriteRules(mixed $rules): GuestWriteRuleCollection
    {
        return new GuestWriteRuleCollection(array_map(
            static fn (array $rule): GuestWriteRule => new GuestWriteRule(
                EventKindCollection::fromInts($rule['kinds'] ?? null),
                (bool) ($rule['tagged_to_tenant'] ?? false),
            ),
            self::listOfArrays($rules),
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        return array_values(self::asArray($value));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        return array_values(array_filter(self::asArray($value), is_array(...)));
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
