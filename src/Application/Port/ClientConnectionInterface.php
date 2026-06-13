<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

interface ClientConnectionInterface
{
    public function sendText(string $text): void;
}
