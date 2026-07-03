<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Server;

use Innis\Nostr\Relay\Application\Port\ConnectionGateInterface;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Override;

final readonly class AllowAllConnectionGate implements ConnectionGateInterface
{
    #[Override]
    public function isIpAllowed(IpAddress $ipAddress): bool
    {
        return true;
    }
}
