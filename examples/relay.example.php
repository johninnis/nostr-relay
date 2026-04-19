<?php

declare(strict_types=1);

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
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

    public function countByFilters(array $filters): int
    {
        return 0;
    }

    public function deleteByEventIds(array $eventIds, PublicKey $author): int
    {
        return 0;
    }

    public function deleteByCoordinates(array $coordinates, PublicKey $author): int
    {
        return 0;
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

    public function getRelayUrl(): RelayUrl
    {
        return RelayUrl::fromString('ws://127.0.0.1:8080');
    }

    public function getRelayInfo(): Nip11Info
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');

        return Nip11Info::fromArray($relayUrl, [
            'name' => 'My Private Relay',
            'description' => 'Private Nostr relay',
            'pubkey' => $this->ownerPubkeyHex,
            'contact' => 'admin@example.com',
            'supported_nips' => [1, 11, 42],
            'software' => 'innis/nostr-relay',
            'version' => '1.0.0',
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
$authManager = new AuthenticationManager();
$logger = new \Psr\Log\NullLogger();

$policy = new RelayPolicy($authManager, $logger, [
    'tenants' => [$ownerPubkeyHex],
    'guest' => [
        'read' => [
            ['kinds' => [0, 1, 6, 7, 30023], 'from' => 'tenants'],
        ],
        'write' => [
            ['kinds' => [7, 9735]],
        ],
    ],
]);

$factory = new \Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory(
    eventStore: new ExampleEventStore(),
    policy: $policy,
    config: new ExampleRelayConfig($ownerPubkeyHex),
    authManager: $authManager,
    logger: $logger,
);

$relay = $factory->create();
$relay->start();
