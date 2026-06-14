<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Innis\Nostr\Core\Domain\Service\EventValidationService;
use Innis\Nostr\Core\Domain\Service\NipComplianceValidator;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Infrastructure\Adapter\JsonMessageSerialiserAdapter;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Application\Port\HttpRequestHandlerInterface;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayEventStoreInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\ClientDisconnectionHandler;
use Innis\Nostr\Relay\Application\Service\ClientManager;
use Innis\Nostr\Relay\Application\Service\EventDistributor;
use Innis\Nostr\Relay\Application\Service\MessageRouter;
use Innis\Nostr\Relay\Application\Service\SubscriptionAdmission;
use Innis\Nostr\Relay\Application\Service\SubscriptionManager;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CloseSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CountSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ManageSubscription\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuth\ProcessAuthUseCase;
use Innis\Nostr\Relay\Application\UseCase\ProcessEventSubmission\ProcessEventSubmissionUseCase;
use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;
use Innis\Nostr\Relay\Infrastructure\Concurrency\AmphpDeferredExecutorAdapter;
use Innis\Nostr\Relay\Infrastructure\Http\ConfigNip11InfoAdapter;
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
        private readonly AuthenticationManager $authManager,
        private readonly LoggerInterface $logger,
        private readonly ?HttpRequestHandlerInterface $httpHandler = null,
        private readonly ?Nip11InfoProviderInterface $nip11InfoProvider = null,
        ?SignatureServiceInterface $signatureService = null,
        private readonly ?ConnectionGateInterface $connectionGate = null,
    ) {
        $this->signatureService = $signatureService ?? Secp256k1SignatureAdapter::create();
    }

    public function create(): RelayInstance
    {
        $metrics = new InMemoryMetricsCollector();

        $subscriptionManager = new SubscriptionManager(
            $metrics,
            $this->logger
        );

        $clientManager = new ClientManager(
            $subscriptionManager,
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

        $eventRateLimiter = new TokenBucketRateLimiter($this->config, RateLimitMetric::Events);
        $subscriptionRateLimiter = new TokenBucketRateLimiter($this->config, RateLimitMetric::Subscriptions);

        $eventValidator = new EventValidationService(
            $this->signatureService,
            new NipComplianceValidator($this->signatureService)
        );

        $deferredExecutor = new AmphpDeferredExecutorAdapter();

        $subscriptionAdmission = new SubscriptionAdmission(
            $this->policy,
            $subscriptionRateLimiter,
            $authManager,
            $clientManager
        );

        $processEventUseCase = new ProcessEventSubmissionUseCase(
            $this->eventStore,
            $this->policy,
            $eventDistributor,
            $authManager,
            $eventRateLimiter,
            $metrics,
            $this->logger,
            $eventValidator,
            $clientManager,
            $deferredExecutor
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

        $serialiser = new JsonMessageSerialiserAdapter();

        $messageRouter = new MessageRouter(
            $processEventUseCase,
            $createSubscriptionUseCase,
            $closeSubscriptionUseCase,
            $processAuthUseCase,
            $countSubscriptionUseCase,
            $serialiser,
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

        $nip11InfoProvider = $this->nip11InfoProvider ?? new ConfigNip11InfoAdapter($this->config);
        $nip11Handler = new Nip11HttpHandler($nip11InfoProvider);

        $server = new AmphpRelayServer(
            $this->config,
            $connectionHandler,
            $nip11Handler,
            $this->logger,
            $this->httpHandler,
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
