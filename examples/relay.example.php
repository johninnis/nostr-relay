<?php

declare(strict_types=1);

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\EventCollection;
use Innis\Nostr\Core\Domain\Entity\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventIdCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Psr\Log\NullLogger;
use RuntimeException;

require __DIR__.'/../vendor/autoload.php';

final class ExampleEventStore implements RelayEventStoreInterface
{
    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        return EventStoreOutcome::Stored;
    }

    #[Override]
    public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection
    {
        return new EventCollection([]);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters): int
    {
        return 0;
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        return 0;
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
    {
        return 0;
    }
}

final class ExampleRelayConfig implements RelayConfigInterface
{
    public function __construct(
        private readonly string $ownerPubkeyHex,
    ) {
    }

    #[Override]
    public function getHost(): string
    {
        return '127.0.0.1';
    }

    #[Override]
    public function getPort(): int
    {
        return 8080;
    }

    #[Override]
    public function getMaxConnections(): int
    {
        return 1000;
    }

    #[Override]
    public function getRelayUrl(): RelayUrl
    {
        return RelayUrl::fromString('ws://127.0.0.1:8080')
            ?? throw new RuntimeException('invalid relay URL');
    }

    #[Override]
    public function getRelayInfo(): Nip11Info
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com')
            ?? throw new RuntimeException('invalid relay URL');

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

    #[Override]
    public function getTrustedProxies(): array
    {
        return [];
    }
}

$ownerPubkeyHex = 'your-hex-pubkey-here';
$authManager = new AuthenticationManager();
$logger = new NullLogger();

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

$rateLimitPolicy = new StaticRateLimitPolicy(new RateLimitConfig(
    eventsPerMinute: 60,
    subscriptionsPerMinute: 20,
));

$factory = new RelayServerFactory(
    eventStore: new ExampleEventStore(),
    policy: $policy,
    config: new ExampleRelayConfig($ownerPubkeyHex),
    rateLimitPolicy: $rateLimitPolicy,
    authManager: $authManager,
    logger: $logger,
);

$relay = $factory->create();
$relay->start();
