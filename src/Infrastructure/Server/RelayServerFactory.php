<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\ErrorHandler;
use Innis\Nostr\Core\Application\Port\RandomBytesGeneratorInterface;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Application\Port\HttpRequestHandlerInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPipeline;
use Innis\Nostr\Relay\Application\Service\AcceptedEventPublisher;
use Innis\Nostr\Relay\Application\Service\AuthenticationRegistryInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\ClientMessageDispatcher;
use Innis\Nostr\Relay\Application\Service\ClientMessenger;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\InMemorySubscriptionRegistry;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\StoredEventStreamer;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionReevaluator;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use Innis\Nostr\Relay\Infrastructure\Monitoring\InMemoryMetricsCollector;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\TokenBucketRateLimiter;
use Override;
use Psr\Log\LoggerInterface;

final class RelayServerFactory
{
    private readonly SignatureServiceInterface $signatureService;
    private readonly RandomBytesGeneratorInterface $randomBytes;

    // Deliberate: composition root wiring the object graph — see ADR-0010
    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly RelayConfigInterface $config,
        private readonly RateLimitPolicyInterface $rateLimitPolicy,
        private readonly AuthenticationRegistryInterface $authManager,
        private readonly LoggerInterface $logger,
        private readonly Nip11InfoProviderInterface $nip11InfoProvider,
        private readonly ?HttpRequestHandlerInterface $httpHandler = null,
        ?SignatureServiceInterface $signatureService = null,
        private readonly ?ConnectionGateInterface $connectionGate = null,
        private readonly ?ErrorHandler $errorHandler = null,
        private readonly ?MetricsCollectorInterface $metricsCollector = null,
        ?RandomBytesGeneratorInterface $randomBytes = null,
    ) {
        $this->signatureService = $signatureService ?? Secp256k1Signer::create();
        $this->randomBytes = $randomBytes ?? new NativeRandomBytesGenerator();
    }

    public function create(): RelayInstance
    {
        $metrics = $this->metricsCollector ?? new InMemoryMetricsCollector();

        $subscriptionManager = new InMemorySubscriptionRegistry(
            $metrics,
            $this->logger
        );

        $clientManager = new InMemoryClientRegistry(
            $metrics,
            $this->randomBytes,
            $this->logger,
            $this->config->getMaxConnections()
        );

        $clientMessenger = new ClientMessenger($clientManager);

        $disconnectionHandler = new ClientDisconnectionHandler(
            $clientManager,
            $subscriptionManager,
            $this->authManager,
            $this->logger
        );

        $eventDistributor = new EventDistributor(
            $this->policy,
            $subscriptionManager,
            $clientManager,
            $clientMessenger,
            $this->logger
        );

        $eventRateLimiter = new TokenBucketRateLimiter($this->rateLimitPolicy, RateLimitMetric::Events);
        $subscriptionRateLimiter = new TokenBucketRateLimiter($this->rateLimitPolicy, RateLimitMetric::Subscriptions);

        $eventValidator = new EventValidator(
            $this->signatureService,
            new NipComplianceValidator($this->signatureService)
        );

        $deferredExecutor = new AmphpDeferredExecutor();

        $subscriptionAdmission = new SubscriptionAdmission(
            $this->policy,
            $subscriptionRateLimiter,
            $this->authManager,
            $clientMessenger,
            $subscriptionManager
        );

        $eventDeletionProcessor = new EventDeletionProcessor($this->eventStore, $this->logger);

        $eventAdmission = new EventAdmission(
            $this->policy,
            $eventRateLimiter,
            $eventValidator
        );

        $acceptedEventPublisher = new AcceptedEventPublisher(
            $clientManager,
            $eventDistributor,
            $deferredExecutor
        );

        $acceptedEventPipeline = new AcceptedEventPipeline(
            $this->eventStore,
            $acceptedEventPublisher,
            $eventDeletionProcessor,
            $this->logger
        );

        $processEventUseCase = new ProcessEventSubmissionUseCase(
            $eventAdmission,
            $acceptedEventPipeline,
            $this->authManager,
            $clientManager,
            $this->logger
        );

        $storedEventStreamer = new StoredEventStreamer(
            $this->eventStore,
            $this->policy,
            $clientMessenger,
            $subscriptionManager,
            $this->logger
        );

        $createSubscriptionUseCase = new CreateSubscriptionUseCase(
            $subscriptionAdmission,
            $subscriptionManager,
            $storedEventStreamer,
            $deferredExecutor,
            $this->logger
        );

        $closeSubscriptionUseCase = new CloseSubscriptionUseCase(
            $subscriptionManager,
            $this->logger
        );

        $subscriptionReevaluator = new SubscriptionReevaluator(
            $subscriptionManager,
            $createSubscriptionUseCase,
            $clientMessenger
        );

        $authEventVerifier = new AuthEventVerifier($this->config, $this->policy, new SystemClock());

        $processAuthUseCase = new ProcessAuthUseCase(
            $this->authManager,
            $authEventVerifier,
            $eventValidator,
            $subscriptionReevaluator,
            $this->logger
        );

        $countSubscriptionUseCase = new CountSubscriptionUseCase(
            $this->eventStore,
            $subscriptionAdmission,
            $this->logger
        );

        $deserialiser = new JsonMessageDeserialiser();

        $messageDispatcher = new ClientMessageDispatcher(
            $deserialiser,
            $this->logger,
            $processEventUseCase,
            $createSubscriptionUseCase,
            $closeSubscriptionUseCase,
            $processAuthUseCase,
            $countSubscriptionUseCase,
        );

        $messageRouter = new MessageRouter(
            $messageDispatcher,
            $clientMessenger,
            $this->logger
        );

        $connectionHandler = new ClientConnectionHandler(
            $clientManager,
            $disconnectionHandler,
            $messageRouter,
            $this->logger,
            $this->connectionGate ?? new class implements ConnectionGateInterface {
                #[Override]
                public function isIpAllowed(IpAddress $ipAddress): bool
                {
                    return true;
                }
            },
        );

        $nip11Handler = new Nip11HttpHandler($this->nip11InfoProvider);

        $server = new AmphpRelayServer(
            $this->config,
            $connectionHandler,
            $nip11Handler,
            $this->logger,
            $this->httpHandler,
            $this->errorHandler,
        );

        return new RelayInstance(
            $server,
            $eventDistributor,
            $subscriptionManager,
            $clientManager,
            $metrics
        );
    }
}
