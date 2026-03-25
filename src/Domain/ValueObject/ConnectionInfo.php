<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class ConnectionInfo
{
    public function __construct(
        private string $ipAddress,
        private string $userAgent,
        private Timestamp $connectedAt,
    ) {
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getConnectedAt(): Timestamp
    {
        return $this->connectedAt;
    }
}
