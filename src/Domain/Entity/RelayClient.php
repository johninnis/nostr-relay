<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Entity;

use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;

final readonly class RelayClient
{
    public function __construct(
        private ClientId $id,
        private ConnectionInfo $connectionInfo,
    ) {
    }

    public function getId(): ClientId
    {
        return $this->id;
    }

    public function getConnectionInfo(): ConnectionInfo
    {
        return $this->connectionInfo;
    }
}
