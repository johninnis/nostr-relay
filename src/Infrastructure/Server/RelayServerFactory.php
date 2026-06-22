<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Amp\Http\Server\ErrorHandler;
use Innis\Nostr\Core\Domain\Service\EventValidator;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Application\Port\HttpRequestHandlerInterface;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Application\Port\RateLimitPolicyInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventDeletionProcessor;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutor;
use Innis\Nostr\Relay\Infrastructure\Http\ConfigNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\Http\Nip11HttpHandler;
use Innis\Nostr\Relay\Infrastructure\Monitoring\InMemoryMetricsCollector;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\TokenBucketRateLimiter;
use Psr\Log\LoggerInterface;

final class RelayServerFactory
{
    private readonly SignatureServiceInterface $signatureService;

    public function __construct(
        private readonly RelayEventStoreInterface $eventStore,
        private readonly RelayPolicyInterface $policy,
        private readonly RelayConfigInterface $config,
        private readonly RateLimitPolicyInterface $rateLimitPolicy,
        private readonly AuthenticationManager $authManager,
        private readonly LoggerInterface $logger,
        private readonly ?HttpRequestHandlerInterface $httpHandler = null,
        private readonly ?Nip11InfoProviderInterface $nip11InfoProvider = null,
        ?SignatureServiceInterface $signatureService = null,
        private readonly ?ConnectionGateInterface $connectionGate = null,
        private readonly ?ErrorHandler $errorHandler = null,
    ) {
        $this->signatureService = $signatureService ?? Secp256k1Signer::create();
    }

    public function create(): RelayInstance
    {
        $metrics = new InMemoryMetricsCollector();

        $subscriptionManager = new SubscriptionManager(
            $metrics,
            $this->logger
        );

        $clientManager = new ClientManager(
            $metrics,
            $this->logger,
            $this->config->getMaxConnections()
        );

        $authManager = $this->authManager;

        $disconnectionHandler = new ClientDisconnectionHandler(
            $clientManager,
            $subscriptionManager,
            $authManager,
            $this->logger
        );

        $eventDistributor = new EventDistributor(
            $this->policy,
            $subscriptionManager,
            $clientManager,
            $metrics,
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
            $authManager,
            $clientManager,
            $subscriptionManager
        );

        $eventDeletionProcessor = new EventDeletionProcessor($this->eventStore, $this->logger);

        $eventAdmission = new EventAdmission(
            $this->policy,
            $eventRateLimiter,
            $eventValidator
        );

        $processEventUseCase = new ProcessEventSubmissionUseCase(
            $this->eventStore,
            $eventAdmission,
            $eventDistributor,
            $authManager,
            $metrics,
            $this->logger,
            $clientManager,
            $deferredExecutor,
            $eventDeletionProcessor
        );

        $createSubscriptionUseCase = new CreateSubscriptionUseCase(
            $this->eventStore,
            $this->policy,
            $subscriptionManager,
            $subscriptionAdmission,
            $clientManager,
            $deferredExecutor,
            $this->logger
        );

        $closeSubscriptionUseCase = new CloseSubscriptionUseCase(
            $subscriptionManager,
            $this->logger
        );

        $processAuthUseCase = new ProcessAuthUseCase(
            $authManager,
            $this->config,
            $this->policy,
            $this->logger,
            $eventValidator,
            $clientManager,
            $subscriptionManager,
            $createSubscriptionUseCase
        );

        $countSubscriptionUseCase = new CountSubscriptionUseCase(
            $this->eventStore,
            $subscriptionAdmission,
            $clientManager,
            $this->logger
        );

        $deserialiser = new JsonMessageDeserialiser();

        $messageRouter = new MessageRouter(
            $processEventUseCase,
            $createSubscriptionUseCase,
            $closeSubscriptionUseCase,
            $processAuthUseCase,
            $countSubscriptionUseCase,
            $deserialiser,
            $clientManager,
            $this->logger
        );

        $connectionHandler = new ClientConnectionHandler(
            $clientManager,
            $disconnectionHandler,
            $messageRouter,
            $this->logger,
            $this->connectionGate ?? new class implements ConnectionGateInterface {
                public function isIpAllowed(string $ipAddress): bool
                {
                    return true;
                }
            },
        );

        $nip11InfoProvider = $this->nip11InfoProvider ?? new ConfigNip11InfoProvider($this->config);
        $nip11Handler = new Nip11HttpHandler($nip11InfoProvider);

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
