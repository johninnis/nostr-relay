<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Http;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;

final readonly class ConfigNip11InfoAdapter implements Nip11InfoProviderInterface
{
    public function __construct(
        private RelayConfigInterface $config,
    ) {
    }

    public function getNip11Info(): Nip11Info
    {
        return $this->config->getRelayInfo();
    }
}
