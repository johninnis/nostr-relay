<?php

declare(strict_types=1);

/*
 * Soak + hostile-client harness.
 *
 * Drives the real factory-built relay graph over transport-free client sessions that
 * stream adversarial frames (malformed JSON, wrong-typed arrays, unknown message types,
 * oversized payloads, unsolicited auth, floods) and drop mid-session, under sustained
 * randomised churn of connect / subscribe / publish / close across many clients.
 *
 * The relay is assembled through RelayServerFactory exactly as production assembles it;
 * the harness drives ClientSessionCoordinator (exposed on RelayInstance) with a fake
 * ClientConnectionInterface, so every use case, registry and index runs for real without
 * a socket.
 *
 * Invariants checked:
 *   - only a ConnectionException may escape a session operation; any other throwable is a
 *     defect and fails the run;
 *   - no client ever receives an "Internal server error" NOTICE (the router's last-resort
 *     backstop firing means a non-transport throwable escaped a use case);
 *   - the registered client count never exceeds the configured max connections;
 *   - after teardown the client registry, subscription registry and metrics counters all
 *     return to zero (no client, subscription or kind-index leak);
 *   - resident memory does not trend upward across the run (no unbounded retention).
 *
 * Usage: php tools/soak-harness.php [iterations] [maxConnections] [seed]
 */

require __DIR__.'/../vendor/autoload.php';

use Amp\Http\Server\SocketHttpServer;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CountMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage as ClientEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use Innis\Nostr\Relay\Tests\Support\InMemoryEventStore;
use Innis\Nostr\Relay\Tests\Support\RecordingClientConnection;
use Psr\Log\NullLogger;

use function Amp\delay;

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 5000;
$maxConnections = isset($argv[2]) ? max(1, (int) $argv[2]) : 32;
$seed = isset($argv[3]) ? (int) $argv[3] : 20260721;

mt_srand($seed);

$signer = Secp256k1Signer::create();
$keyPair = KeyPair::generate($signer);

$relayUrl = RelayUrl::tryFromString('wss://soak.relay.test')
    ?? throw new RuntimeException('bad relay url');

$config = new class($relayUrl, $maxConnections) implements RelayConfigInterface {
    public function __construct(
        private readonly RelayUrl $relayUrl,
        private readonly int $maxConnections,
    ) {
    }

    #[Override]
    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    #[Override]
    public function getRelayUrl(): RelayUrl
    {
        return $this->relayUrl;
    }
};

$authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

$factory = new RelayServerFactory(
    eventStore: new InMemoryEventStore(),
    policy: new RelayPolicy($authenticationRegistry, new NullLogger(), RelayPolicyConfig::tryFromArray([]) ?? throw new RuntimeException('bad policy config')),
    config: $config,
    rateLimitPolicy: new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 100000, subscriptionsPerMinute: 100000)),
    authenticationRegistry: $authenticationRegistry,
    logger: new NullLogger(),
    nip11InfoProvider: new StaticNip11InfoProvider(Nip11Info::fromArray($relayUrl, ['name' => 'Soak Relay', 'supported_nips' => [1, 11]])),
);

$relay = $factory->create(SocketHttpServer::createForDirectAccess(new NullLogger()));
$coordinator = $relay->getSessionCoordinator();

$signedTextNote = static function (string $content) use ($keyPair, $signer): Event {
    return new Rumour(
        $keyPair->getPublicKey(),
        Timestamp::now(),
        EventKind::fromInt(EventKind::TEXT_NOTE),
        new TagCollection(),
        EventContent::fromString($content),
    )->sign($keyPair, $signer);
};

// Signing is expensive; pre-sign a bounded pool of legitimate frames so the accept /
// store / distribute path runs without dominating the run with cryptography.
/** @var list<string> $validEventFrames */
$validEventFrames = [];
for ($i = 0; $i < 8; ++$i) {
    $validEventFrames[] = new ClientEventMessage($signedTextNote('soak note '.$i))->toJson();
}

/** @var list<string> $authFrames */
$authFrames = [];
for ($i = 0; $i < 4; ++$i) {
    $authEvent = RumourFactory::createAuth($keyPair->getPublicKey(), $relayUrl, 'challenge-'.$i)->sign($keyPair, $signer);
    $authFrames[] = new AuthMessage($authEvent)->toJson();
}

$allFilters = new FilterCollection([new Filter()]);

/**
 * A client-supplied frame, drawn adversarially. A subscription id is threaded in so some
 * frames legitimately open, count against or close a live subscription.
 */
$hostileFrame = static function (string $subscriptionId) use ($validEventFrames, $authFrames, $allFilters): string {
    $subId = SubscriptionId::tryFromString($subscriptionId);
    $legitimate = [
        $validEventFrames[mt_rand(0, count($validEventFrames) - 1)],
        $authFrames[mt_rand(0, count($authFrames) - 1)],
    ];

    if (null !== $subId) {
        $legitimate[] = new ReqMessage($subId, $allFilters)->toJson();
        $legitimate[] = new CloseMessage($subId)->toJson();
        $legitimate[] = new CountMessage($subId, $allFilters)->toJson();
    }

    $menu = [
        ...$legitimate,
        // Malformed / adversarial:
        '{',
        '',
        'not json at all',
        '["unterminated',
        '[]',
        '["EVENT"]',
        '["REQ"]',
        '["REQ","'.$subscriptionId.'"]',
        '["EVENT",123,{}]',
        '["EVENT",{"an":"object"}]',
        '["CLOSE"]',
        '["OK","not-a-client-message",true,""]',
        '12345',
        'true',
        'null',
        '{"an":"object"}',
        '["WAT_UNKNOWN",1,2,3]',
        '["REQ","'.$subscriptionId.'",{"kinds":"not-an-array"}]',
        '["EVENT","'.$subscriptionId.'",{"kind":"not-an-int"}]',
        sprintf('["REQ","%s",%s]', $subscriptionId, '{"authors":['.str_repeat('"x",', 20000).'"x"]}'),
    ];

    return $menu[mt_rand(0, count($menu) - 1)];
};

$connectionInfo = static function (): ConnectionInfo {
    return new ConnectionInfo(
        IpAddress::fromString(sprintf('10.%d.%d.%d', mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254))),
        'soak-client',
        Timestamp::now(),
    );
};

/** @var array<string, RelayClient> $clientsById */
$clientsById = [];
/** @var array<string, RecordingClientConnection> $connectionsById */
$connectionsById = [];
/** @var list<RecordingClientConnection> $allConnections */
$allConnections = [];
/** @var list<array{iteration: int, operation: string, error: string}> $violations */
$violations = [];
/** @var array<string, int> $opCounts */
$opCounts = [];
/** @var list<int> $memorySamples */
$memorySamples = [];
$connectionExceptions = 0;
$maxObservedClients = 0;
$lastSubscriptionId = SubscriptionId::generate();

$pickClientId = static function () use (&$clientsById): ?string {
    /** @var array<string, RelayClient> $clientsById */
    $ids = array_keys($clientsById);

    return [] === $ids ? null : $ids[mt_rand(0, count($ids) - 1)];
};

$started = hrtime(true);

for ($step = 0; $step < $iterations; ++$step) {
    $roll = mt_rand(0, 99);
    $operation = ([] === $clientsById || $roll < 25) ? 'open' : ($roll < 80 ? 'route' : 'close');
    $opCounts[$operation] = ($opCounts[$operation] ?? 0) + 1;

    try {
        switch ($operation) {
            case 'open':
                $connection = new RecordingClientConnection(failAfterSends: mt_rand(0, 30));
                $allConnections[] = $connection;
                $client = $coordinator->open($connection, $connectionInfo());
                $key = (string) $client->getId();
                $clientsById[$key] = $client;
                $connectionsById[$key] = $connection;
                break;
            case 'route':
                $id = $pickClientId();
                if (null !== $id) {
                    if (0 === mt_rand(0, 3)) {
                        $lastSubscriptionId = SubscriptionId::generate();
                    }
                    $coordinator->route($clientsById[$id], $hostileFrame((string) $lastSubscriptionId));
                }
                break;
            case 'close':
                $id = $pickClientId();
                if (null !== $id) {
                    $coordinator->close($clientsById[$id]->getId());
                    unset($clientsById[$id], $connectionsById[$id]);
                }
                break;
        }
    } catch (ConnectionException) {
        ++$connectionExceptions;
    } catch (Throwable $e) {
        $violations[] = [
            'iteration' => $step,
            'operation' => $operation,
            'error' => $e::class.': '.$e->getMessage(),
        ];
    } finally {
        delay(0);
    }

    $maxObservedClients = max($maxObservedClients, $relay->getClients()->count());

    if (0 === $step % 200) {
        gc_collect_cycles();
        $memorySamples[] = memory_get_usage();
    }
}

foreach (array_keys($clientsById) as $id) {
    try {
        $coordinator->close($clientsById[$id]->getId());
    } catch (Throwable) {
    }
    unset($clientsById[$id], $connectionsById[$id]);
}

for ($settle = 0; $settle < 50; ++$settle) {
    delay(0);
}
gc_collect_cycles();

$metrics = $relay->getMetrics();
$leftClients = $relay->getClients()->count();
$leftSubscriptions = $relay->getSubscriptions()->count();
$elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

$internalErrors = 0;
foreach ($allConnections as $connection) {
    foreach ($connection->sentFrames() as $frame) {
        if (str_contains($frame, 'Internal server error')) {
            ++$internalErrors;
        }
    }
}

// Leak signal: compare the median of the last quarter of samples against the first
// quarter taken after warmup. A steadily-retaining run trends upward; a healthy one is flat.
$median = static function (array $values): int {
    sort($values);
    $count = count($values);

    return 0 === $count ? 0 : (int) $values[intdiv($count, 2)];
};
$sampleCount = count($memorySamples);
$warm = array_slice($memorySamples, (int) ($sampleCount * 0.2));
$warmCount = count($warm);
$earlyMedian = $median(array_slice($warm, 0, max(1, (int) ($warmCount * 0.25))));
$lateMedian = $median(array_slice($warm, (int) ($warmCount * 0.75)));
$growthBytes = $lateMedian - $earlyMedian;
$peakBytes = memory_get_peak_usage();

$mib = static fn (int $bytes): string => number_format($bytes / 1048576, 1).' MiB';

echo "== Soak + hostile-client harness ==\n";
echo sprintf("seed=%d max-connections=%d iterations=%d elapsed=%dms\n", $seed, $maxConnections, $iterations, $elapsedMs);
echo sprintf("operations: %s\n", json_encode($opCounts, JSON_THROW_ON_ERROR));
echo sprintf("connection-exceptions (expected): %d\n", $connectionExceptions);
echo sprintf("events-received=%d events-sent=%d\n", $metrics->getTotalEventsReceived(), $metrics->getTotalEventsSent());
echo sprintf("max registered clients: %d (configured max %d)\n", $maxObservedClients, $maxConnections);
echo sprintf("memory: early=%s late=%s growth=%s peak=%s\n", $mib($earlyMedian), $mib($lateMedian), $mib($growthBytes), $mib($peakBytes));
echo sprintf("after teardown: clients=%d subscriptions=%d active-connections=%d subscriptions-counter=%d\n", $leftClients, $leftSubscriptions, $metrics->getActiveConnections(), $metrics->getTotalSubscriptions());

$leak = $growthBytes > 16 * 1048576;
$connectionLeak = $maxObservedClients > $maxConnections;
$teardownIncomplete = 0 !== $leftClients || 0 !== $leftSubscriptions || 0 !== $metrics->getActiveConnections() || 0 !== $metrics->getTotalSubscriptions();
$ok = [] === $violations && 0 === $internalErrors && !$leak && !$connectionLeak && !$teardownIncomplete;

if ([] !== $violations) {
    echo "\nINVARIANT VIOLATIONS (only ConnectionException may escape a session operation):\n";
    foreach (array_slice($violations, 0, 20) as $violation) {
        echo sprintf("  iter %d op %s -> %s\n", $violation['iteration'], $violation['operation'], $violation['error']);
    }
    echo sprintf("  ... %d total\n", count($violations));
}
if (0 !== $internalErrors) {
    echo sprintf("\nBACKSTOP FIRED: %d 'Internal server error' notices — a use case leaked a throwable.\n", $internalErrors);
}
if ($leak) {
    echo "\nPOTENTIAL LEAK: resident memory trended up beyond threshold.\n";
}
if ($connectionLeak) {
    echo "\nCONNECTION LEAK: registered clients exceeded the configured max.\n";
}
if ($teardownIncomplete) {
    echo "\nTEARDOWN INCOMPLETE: client, subscription or metrics state remained after close.\n";
}

echo "\nRESULT: ".($ok ? 'PASS' : 'FAIL')."\n";

exit($ok ? 0 : 1);
