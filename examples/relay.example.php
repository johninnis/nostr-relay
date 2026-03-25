<?php

declare(strict_types=1);

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\PolicyViolationException;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;

class ExampleEventStore implements RelayEventStoreInterface
{
    public function store(Event $event): bool
    {
        return true;
    }

    public function findByFilters(array $filters, int $limit = 100): array
    {
        return [];
    }
}

class PrivateRelayPolicy implements RelayPolicyInterface
{
    public function __construct(
        private readonly string $ownerPubkeyHex,
    ) {
    }

    public function allowEventSubmission(RelayClient $client, Event $event): void
    {
        if ($event->getPubkey()->toHex() !== $this->ownerPubkeyHex) {
            throw new PolicyViolationException('Only events from relay owner allowed');
        }

        if ($event->getContent()->getLength() > 65536) {
            throw new PolicyViolationException('Event too large (max 64KB)');
        }
    }

    public function allowSubscription(RelayClient $client, array $filters): void
    {
        if ($client->getSubscriptionCount() >= 20) {
            throw new PolicyViolationException('Too many subscriptions (max 20)');
        }

        if (count($filters) > 5) {
            throw new PolicyViolationException('Too many filters (max 5)');
        }

        foreach ($filters as $filter) {
            if ($filter->hasLimit() && $filter->getLimit() > 1000) {
                throw new PolicyViolationException('Filter limit too high (max 1000)');
            }
        }
    }

    public function filterForClient(RelayClient $client, array $filters): array
    {
        return array_map(
            fn (Filter $filter) => $filter->withAuthors([$this->ownerPubkeyHex]),
            $filters
        );
    }

    public function canClientReceiveEvent(RelayClient $client, Event $event): bool
    {
        return $event->getPubkey()->toHex() === $this->ownerPubkeyHex;
    }

    public function getMaxSubscriptionsPerClient(): int
    {
        return 20;
    }
}

class ExampleRelayConfig implements RelayConfigInterface
{
    public function __construct(
        private readonly string $ownerPubkeyHex,
    ) {
    }

    public function getHost(): string
    {
        return '127.0.0.1';
    }

    public function getPort(): int
    {
        return 8080;
    }

    public function getMaxConnections(): int
    {
        return 1000;
    }

    public function getRelayInfo(): Nip11Info
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');

        return Nip11Info::fromArray($relayUrl, [
            'name' => 'My Private Relay',
            'description' => 'Private Nostr relay',
            'pubkey' => $this->ownerPubkeyHex,
            'contact' => 'admin@example.com',
            'supported_nips' => [1, 11],
            'software' => 'innis/nostr-relay',
            'version' => '1.0.0',
            'limitation' => [
                'max_message_length' => 65536,
                'max_subscriptions' => 20,
                'max_filters' => 5,
                'max_limit' => 1000,
                'max_event_tags' => 2000,
                'max_content_length' => 65536,
                'auth_required' => false,
                'payment_required' => false,
                'restricted_writes' => true,
            ],
        ]);
    }

    public function getRateLimitConfig(): RateLimitConfig
    {
        return new RateLimitConfig(
            eventsPerMinute: 60,
            subscriptionsPerMinute: 20,
        );
    }

    public function getTrustedProxies(): array
    {
        return [];
    }
}

$ownerPubkeyHex = 'your-hex-pubkey-here';

$factory = new \Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory(
    new ExampleEventStore(),
    new PrivateRelayPolicy($ownerPubkeyHex),
    new ExampleRelayConfig($ownerPubkeyHex),
    new \Psr\Log\NullLogger()
);

$relay = $factory->create();
$relay->start();
