<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Relay\Domain\Entity\RelayClient;

interface ClientAuthChallengerInterface
{
    public function offer(RelayClient $client): void;
}
