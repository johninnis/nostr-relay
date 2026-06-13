<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Closure;

interface DeferredExecutorInterface
{
    public function defer(Closure $task): void;
}
