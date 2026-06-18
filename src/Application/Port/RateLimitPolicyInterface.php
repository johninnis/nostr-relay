<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Relay\Domain\Enum\RateLimitMetric;

interface RateLimitPolicyInterface
{
    public function limitFor(RateLimitMetric $metric): int;
}
