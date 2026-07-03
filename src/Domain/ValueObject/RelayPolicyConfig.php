<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\Collection\GuestWriteRuleCollection;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLimits;

final readonly class RelayPolicyConfig
{
    private const int DEFAULT_MAX_EVENT_SIZE = 65536;
    private const int DEFAULT_MAX_SUBSCRIPTIONS = 20;
    private const int DEFAULT_MAX_FILTERS = 5;
    private const int DEFAULT_MAX_QUERY_LIMIT = 1000;

    public function __construct(
        private PublicKeyCollection $tenants,
        private int $maxEventSize,
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

        return new self(
            $tenants,
            self::intOr($config['max_event_size'] ?? null, self::DEFAULT_MAX_EVENT_SIZE),
            new GuestPolicy(
                EventKindCollection::fromInts(array_merge(...array_map(
                    static fn (array $rule): array => self::asList($rule['kinds'] ?? null),
                    $readRules,
                ))),
                array_any($readRules, static fn (array $rule): bool => 'tenants' === ($rule['from'] ?? null)),
                self::resolveWriteRules($guest['write'] ?? null),
            ),
            new SubscriptionLimits(
                self::intOr($config['max_subscriptions'] ?? null, self::DEFAULT_MAX_SUBSCRIPTIONS),
                self::intOr($config['max_filters'] ?? null, self::DEFAULT_MAX_FILTERS),
                self::intOr($config['max_query_limit'] ?? null, self::DEFAULT_MAX_QUERY_LIMIT),
            ),
        );
    }

    public function getTenants(): PublicKeyCollection
    {
        return $this->tenants;
    }

    public function getMaxEventSize(): int
    {
        return $this->maxEventSize;
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

            $pubkey = PublicKey::fromNpubOrHex($tenant);

            if (null === $pubkey) {
                return null;
            }

            $pubkeys[] = $pubkey;
        }

        return new PublicKeyCollection($pubkeys);
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
