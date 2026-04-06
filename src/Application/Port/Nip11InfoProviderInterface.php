<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;

interface Nip11InfoProviderInterface
{
    public function getNip11Info(): Nip11Info;
}
