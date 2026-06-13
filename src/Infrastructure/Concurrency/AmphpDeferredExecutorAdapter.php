<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Concurrency;

use Closure;
use Innis\Nostr\Relay\Application\Port\DeferredExecutorInterface;

use function Amp\async;

final readonly class AmphpDeferredExecutorAdapter implements DeferredExecutorInterface
{
    public function defer(Closure $task): void
    {
        async($task);
    }
}
