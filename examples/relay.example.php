<?php

declare(strict_types=1);

use Innis\Nostr\Core\Domain\Collection\EventCollection;
use Innis\Nostr\Core\Domain\Collection\EventCoordinateCollection;
use Innis\Nostr\Core\Domain\Collection\EventIdCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\Enum\EventStoreOutcome;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Psr\Log\AbstractLogger;

use function Amp\trapSignal;

require __DIR__.'/../vendor/autoload.php';

/**
 * A non-persistent, in-memory event store. It is enough to run and exercise the relay
 * locally; swap in a durable RelayEventStoreInterface (SQL, etc.) for any real deployment.
 */
final class InMemoryEventStore implements RelayEventStoreInterface
{
    /** @var array<string, Event> */
    private array $events = [];

    #[Override]
    public function store(Event $event): EventStoreOutcome
    {
        $id = $event->getId()->toHex();

        if (isset($this->events[$id])) {
            return EventStoreOutcome::Duplicate;
        }

        $this->events[$id] = $event;

        return EventStoreOutcome::Stored;
    }

    #[Override]
    public function findByFilters(FilterCollection $filters, int $limit = 100): EventCollection
    {
        $matched = [];

        foreach ($this->events as $event) {
            if (count($matched) >= $limit) {
                break;
            }

            foreach ($filters as $filter) {
                if ($filter->matches($event)) {
                    $matched[] = $event;

                    break;
                }
            }
        }

        return new EventCollection($matched);
    }

    #[Override]
    public function countByFilters(FilterCollection $filters): int
    {
        return $this->findByFilters($filters, PHP_INT_MAX)->count();
    }

    #[Override]
    public function deleteByEventIds(EventIdCollection $eventIds, PublicKey $author): int
    {
        $deleted = 0;

        foreach ($eventIds as $eventId) {
            $id = $eventId->toHex();

            if (isset($this->events[$id]) && $this->events[$id]->getPubkey()->equals($author)) {
                unset($this->events[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[Override]
    public function deleteByCoordinates(EventCoordinateCollection $coordinates, PublicKey $author): int
    {
        return 0;
    }
}

final class ExampleRelayConfig implements RelayConfigInterface
{
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
        return RelayUrl::tryFromString('ws://127.0.0.1:8080')
            ?? throw new RuntimeException('invalid relay URL');
    }

    /**
     * @return list<non-empty-string>
     */
    #[Override]
    public function getTrustedProxies(): array
    {
        return [];
    }
}

/**
 * Minimal PSR-3 logger that writes to stderr so you can watch the relay work.
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $label = is_string($level) ? strtoupper($level) : 'LOG';
        $suffix = [] === $context ? '' : ' '.(json_encode($context) ?: '');

        fwrite(STDERR, sprintf('[%s] %s%s%s', $label, (string) $message, $suffix, PHP_EOL));
    }
}

$signer = Secp256k1Signer::create();
$owner = KeyPair::generate($signer);
$ownerPubkeyHex = $owner->getPublicKey()->toHex();

$logger = new StderrLogger();
$authManager = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

// A tenant relay: the owner key may publish and read freely; guests get the configured read/write scope.
// Configure no tenants instead (`RelayPolicyConfig::tryFromArray([])`) to run a fully open public relay.
$policyConfig = RelayPolicyConfig::tryFromArray([
    'tenants' => [$ownerPubkeyHex],
    'guest' => [
        'read' => [
            ['kinds' => [0, 1, 6, 7, 30023], 'from' => 'tenants'],
        ],
        'write' => [
            ['kinds' => [7, 9735]],
        ],
    ],
]) ?? throw new RuntimeException('Invalid relay policy configuration');

$policy = new RelayPolicy($authManager, $logger, $policyConfig);

$rateLimitPolicy = new StaticRateLimitPolicy(new RateLimitConfig(
    eventsPerMinute: 60,
    subscriptionsPerMinute: 20,
));

$config = new ExampleRelayConfig();

$nip11InfoProvider = new StaticNip11InfoProvider(Nip11Info::fromArray($config->getRelayUrl(), [
    'name' => 'Example Nostr Relay',
    'description' => 'A runnable innis/nostr-relay example',
    'pubkey' => $ownerPubkeyHex,
    'contact' => 'admin@example.com',
    'supported_nips' => [1, 9, 11, 42, 45],
    'software' => 'innis/nostr-relay',
    'version' => '1.0.0',
]));

$relay = new RelayServerFactory(
    eventStore: new InMemoryEventStore(),
    policy: $policy,
    config: $config,
    rateLimitPolicy: $rateLimitPolicy,
    authManager: $authManager,
    logger: $logger,
    nip11InfoProvider: $nip11InfoProvider,
)->create();

$relay->start();

$logger->info('Relay started; press Ctrl-C to stop', [
    'listening_on' => (string) ($relay->getListeningAddress() ?? throw new RuntimeException('relay is not listening')),
    'owner_pubkey' => $ownerPubkeyHex,
]);

trapSignal([SIGINT, SIGTERM]);
$relay->stop();
