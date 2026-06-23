<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;

interface ConnectionGateInterface
{
    public function isIpAllowed(IpAddress $ipAddress): bool;
}
