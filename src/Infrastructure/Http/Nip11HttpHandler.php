<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Response;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;

final class Nip11HttpHandler
{
    public function __construct(
        private readonly Nip11InfoProviderInterface $infoProvider,
    ) {
    }

    public function handle(): Response
    {
        $relayInfo = json_encode($this->infoProvider->getNip11Info()->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response(
            HttpStatus::OK,
            [
                'content-type' => 'application/nostr+json',
                'access-control-allow-origin' => '*',
                'access-control-allow-methods' => 'GET',
            ],
            $relayInfo
        );
    }
}
