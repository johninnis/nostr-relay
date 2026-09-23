<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Concurrency;

use Closure;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;

final readonly class AmphpDeferredExecutor implements DeferredExecutorInterface
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // Deliberate: the future is not dropped — amphp discards an unawaited failure without a word, and a deferred task is the one place where nothing above can catch for it
    #[Override]
    public function defer(Closure $task): void
    {
        async($task)->catch($this->report(...));
    }

    private function report(Throwable $error): void
    {
        $this->logger->error('Deferred task failed', [
            'error' => $error->getMessage(),
            'type' => $error::class,
        ]);
    }
}
