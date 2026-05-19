<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

interface ConnectionGateInterface
{
    public function isIpAllowed(string $ipAddress): bool;
}
