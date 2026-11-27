<?php

declare(strict_types=1);

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Innis\Nostr\Core\Domain\ValueObject\EventLimits;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Psr\Log\AbstractLogger;

use function Amp\trapSignal;

require __DIR__.'/../vendor/autoload.php';

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
$authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

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
    'max_query_limit' => 500,
]) ?? throw new RuntimeException('Invalid relay policy configuration');

$policy = new RelayPolicy($authenticationRegistry, $logger, $policyConfig);

$rateLimitPolicy = new StaticRateLimitPolicy(new RateLimitConfig(
    eventsPerMinute: 60,
    subscriptionsPerMinute: 20,
));

$config = new class implements RelayConfigInterface {
    public const string HOST = '127.0.0.1';
    public const int PORT = 8080;

    #[Override]
    public function getMaxConnections(): int
    {
        return 1000;
    }

    #[Override]
    public function getRelayUrl(): RelayUrl
    {
        return RelayUrl::fromString('ws://127.0.0.1:8080');
    }

    #[Override]
    public function getEventLimits(): EventLimits
    {
        return new EventLimits();
    }
};

$nip11InfoProvider = new StaticNip11InfoProvider(Nip11Info::fromArray($config->getRelayUrl(), [
    'name' => 'Example Nostr Relay',
    'description' => 'A runnable innis/nostr-relay example',
    'pubkey' => $ownerPubkeyHex,
    'contact' => 'admin@example.com',
    'supported_nips' => [1, 9, 11, 17, 40, 42, 45, 50, 59, 67, 70],
    'software' => 'https://github.com/johninnis/nostr-relay',
    'version' => '0.13.0',
    'limitation' => [
        'max_limit' => 500,
        'max_subscriptions' => 20,
        'max_filters' => 10,
    ],
]));

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(new InternetAddress($config::HOST, $config::PORT));

$relay = new RelayServerFactory(
    eventStore: new InMemoryEventStore(),
    policy: $policy,
    config: $config,
)
    ->withRateLimitPolicy($rateLimitPolicy)
    ->withAuthenticationRegistry($authenticationRegistry)
    ->withLogger($logger)
    ->withNip11InfoProvider($nip11InfoProvider)
    ->create($httpServer);

$httpServer->start($relay->getRequestHandler(), new DefaultErrorHandler());

$logger->info('Relay started; press Ctrl-C to stop', [
    'owner_pubkey' => $ownerPubkeyHex,
]);

trapSignal([SIGINT, SIGTERM]);
$httpServer->stop();
