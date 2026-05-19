<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Enum;

enum RateLimitMetric
{
    case Events;
    case Subscriptions;
}
