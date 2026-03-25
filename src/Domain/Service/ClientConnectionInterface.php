<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Service;

interface ClientConnectionInterface
{
    public function sendText(string $text): void;

    public function close(): void;
}
