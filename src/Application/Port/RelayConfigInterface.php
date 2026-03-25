<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;

interface RelayConfigInterface
{
    public function getHost(): string;

    public function getPort(): int;

    public function getMaxConnections(): int;

    public function getRelayInfo(): Nip11Info;

    public function getRateLimitConfig(): RateLimitConfig;

    public function getTrustedProxies(): array;
}
