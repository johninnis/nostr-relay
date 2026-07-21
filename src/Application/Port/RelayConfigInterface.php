<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

interface RelayConfigInterface
{
    public function getMaxConnections(): int;

    public function getRelayUrl(): RelayUrl;
}
