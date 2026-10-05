<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\HttpServer;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\Websocket;
use Innis\Nostr\Core\Application\Port\RandomBytesGeneratorInterface;
use Innis\Nostr\Core\Application\Service\Nip42Validator;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\Nip42EventChecker;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPublisher;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\AuthenticationRegistryInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\CappedClientRegistry;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\ClientMessageDispatcher;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\ClientSessionCoordinator;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlers;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventAudience;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\LiveMarkingClientMessenger;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\Nip42Handshake;
use Innis\Nostr\Relay\Application\Service\PublishingGate;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Application\Service\RegisteringStoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\StoredEventReadGate;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionAnswers;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Infrastructure\EventStore\LoggingEventStore;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\Monitoring\InMemoryMetricsCollector;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\TokenBucketRateLimiter;
use Innis\Nostr\Relay\Infrastructure\Time\SystemMonotonicClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RelayServerFactory
{
    private const int MESSAGE_SIZE_LIMIT = 128 * 1024;
    private const int DEFAULT_EVENTS_PER_MINUTE = 60;
    private const int DEFAULT_SUBSCRIPTIONS_PER_MINUTE = 20;

    private ?LoggerInterface $logger = null;
    private ?RateLimitPolicyInterface $rateLimitPolicy = null;
    private ?AuthenticationRegistryInterface $authenticationRegistry = null;
    private ?Nip11InfoProviderInterface $nip11InfoProvider = null;
    private ?SignatureServiceInterface $signatureService = null;
    private ?ConnectionGateInterface $connectionGate = null;
    private ?MetricsCollectorInterface $metricsCollector = null;
    private ?RandomBytesGeneratorInterface $randomBytes = null;

    // Deliberate: the host's irreducible ports are the constructor; everything with a sane default is named wiring — see ADR-0026
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly RelayConfigInterface $config,
    ) {
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;

        return $clone;
    }

    public function withRateLimitPolicy(RateLimitPolicyInterface $rateLimitPolicy): self
    {
        $clone = clone $this;
        $clone->rateLimitPolicy = $rateLimitPolicy;

        return $clone;
    }

    public function withAuthenticationRegistry(AuthenticationRegistryInterface $authenticationRegistry): self
    {
        $clone = clone $this;
        $clone->authenticationRegistry = $authenticationRegistry;

        return $clone;
    }

    public function withNip11InfoProvider(Nip11InfoProviderInterface $nip11InfoProvider): self
    {
        $clone = clone $this;
        $clone->nip11InfoProvider = $nip11InfoProvider;

        return $clone;
    }

    public function withSignatureService(SignatureServiceInterface $signatureService): self
    {
        $clone = clone $this;
        $clone->signatureService = $signatureService;

        return $clone;
    }

    public function withConnectionGate(ConnectionGateInterface $connectionGate): self
    {
        $clone = clone $this;
        $clone->connectionGate = $connectionGate;

        return $clone;
    }

    public function withMetricsCollector(MetricsCollectorInterface $metricsCollector): self
    {
        $clone = clone $this;
        $clone->metricsCollector = $metricsCollector;

        return $clone;
    }

    public function withRandomBytes(RandomBytesGeneratorInterface $randomBytes): self
    {
        $clone = clone $this;
        $clone->randomBytes = $randomBytes;

        return $clone;
    }

    public function create(HttpServer $httpServer): RelayInstance
    {
        $logger = $this->logger ?? new NullLogger();
        $metrics = $this->metricsCollector ?? new InMemoryMetricsCollector();
        $randomBytes = $this->randomBytes ?? new NativeRandomBytesGenerator();
        $signatureService = $this->signatureService ?? Secp256k1Signer::create();
        $authenticationRegistry = $this->authenticationRegistry ?? new InMemoryAuthenticationRegistry($randomBytes);
        $rateLimitPolicy = $this->rateLimitPolicy ?? new StaticRateLimitPolicy(new RateLimitConfig(
            eventsPerMinute: self::DEFAULT_EVENTS_PER_MINUTE,
            subscriptionsPerMinute: self::DEFAULT_SUBSCRIPTIONS_PER_MINUTE,
        ));
        $nip11InfoProvider = $this->nip11InfoProvider ?? new StaticNip11InfoProvider(
            Nip11Info::fromArray($this->config->getRelayUrl()),
        );

        $clock = new SystemClock();
        $eventStore = new LoggingEventStore($this->eventStore, $logger);

        $subscriptionRegistry = new InMemorySubscriptionRegistry($metrics, $logger);
        $clients = new InMemoryClientRegistry($metrics, $randomBytes, $logger);
        $clientRegistry = new CappedClientRegistry($clients, $this->config->getMaxConnections());
        $clientMessenger = new LiveMarkingClientMessenger(new ClientMessenger($clientRegistry), $subscriptionRegistry);
        $disconnectionHandler = new ClientDisconnectionHandler($clientRegistry, $subscriptionRegistry, $authenticationRegistry);

        $eventDistributor = new EventDistributor(
            new EventAudience($this->policy, $subscriptionRegistry, $clientRegistry),
            $clientMessenger,
            $logger,
        );

        $monotonicClock = new SystemMonotonicClock();
        $eventRateLimitGate = new RateLimitGate(new TokenBucketRateLimiter($rateLimitPolicy, RateLimitMetric::Events, $monotonicClock), $this->policy);
        $subscriptionRateLimitGate = new RateLimitGate(new TokenBucketRateLimiter($rateLimitPolicy, RateLimitMetric::Subscriptions, $monotonicClock), $this->policy);

        $eventValidator = new EventValidator(
            $signatureService,
            new NipComplianceValidator($signatureService),
            $this->config->getEventLimits(),
        );
        $validityGate = new EventValidityGate($eventValidator, $clock);

        $deferredExecutor = new AmphpDeferredExecutor($logger);
        $authChallengeIssuer = new AuthChallengeIssuer($authenticationRegistry);

        $eventAdmission = new EventAdmission(
            $eventRateLimitGate,
            $validityGate,
            new PublishingGate($this->policy, $authenticationRegistry, $authChallengeIssuer),
        );
        $subscriptionAdmission = new SubscriptionAdmission($this->policy, $subscriptionRateLimitGate, $subscriptionRegistry);
        $subscriptionAnswers = new SubscriptionAnswers($subscriptionRegistry, $authChallengeIssuer);

        $acceptedEventPipeline = new AcceptedEventPipeline(
            $eventStore,
            new AcceptedEventPublisher($clientRegistry, $eventDistributor, $deferredExecutor),
            new EventDeletionProcessor($eventStore, $logger),
        );

        $storedEventStreamer = new StoredEventStreamer(
            new StoredEventReadGate($eventStore, $this->policy, $clock),
            $clientMessenger,
            $logger,
        );
        $subscriptionActivator = new SubscriptionActivator(
            $subscriptionAdmission,
            new RegisteringStoredEventStreamer($deferredExecutor, $storedEventStreamer, $subscriptionRegistry),
            $subscriptionAnswers,
        );
        $subscriptionReevaluator = new SubscriptionReevaluator($subscriptionRegistry, $subscriptionActivator);

        $messageDispatcher = new ClientMessageDispatcher(
            new ClientVerbHandlers(
                new ProcessEventSubmissionUseCase($eventAdmission, $acceptedEventPipeline, $logger),
                new CreateSubscriptionUseCase($subscriptionActivator),
                new CloseSubscriptionUseCase($subscriptionRegistry),
                new ProcessAuthUseCase(
                    new Nip42Handshake(
                        $authenticationRegistry,
                        new AuthEventVerifier($this->config, $this->policy, new Nip42Validator(new Nip42EventChecker(), $clock)),
                        $authChallengeIssuer,
                    ),
                    $validityGate,
                    $subscriptionReevaluator,
                ),
                new CountSubscriptionUseCase($eventStore, $subscriptionAdmission, $subscriptionAnswers),
            ),
            $clientRegistry,
            $logger,
        );

        $sessionCoordinator = new ClientSessionCoordinator(
            $clientRegistry,
            $disconnectionHandler,
            new MessageRouter($messageDispatcher, $clientMessenger, $logger),
        );

        $connectionHandler = new ClientConnectionHandler(
            $sessionCoordinator,
            $logger,
            $this->connectionGate ?? new AllowAllConnectionGate(),
        );

        $requestHandler = new RelayRequestHandler(
            $this->websocket($httpServer, $connectionHandler, $logger),
            new Nip11HttpHandler($nip11InfoProvider),
        );

        return new RelayInstance(
            $requestHandler,
            $sessionCoordinator,
            new RelayIntrospection($metrics, $clients, $subscriptionRegistry),
        );
    }

    private function websocket(HttpServer $httpServer, ClientConnectionHandler $connectionHandler, LoggerInterface $logger): Websocket
    {
        return new Websocket(
            httpServer: $httpServer,
            logger: $logger,
            acceptor: new Rfc6455Acceptor(),
            clientHandler: new SessionWebsocketClientHandler($connectionHandler),
            clientFactory: new Rfc6455ClientFactory(
                parserFactory: new Rfc6455ParserFactory(messageSizeLimit: self::MESSAGE_SIZE_LIMIT),
            ),
        );
    }
}
