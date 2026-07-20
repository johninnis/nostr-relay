<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Http;

use Innis\Nostr\Relay\Application\DTO\HttpRequestContext;
use Innis\Nostr\Relay\Application\DTO\HttpResponsePayload;
use Innis\Nostr\Relay\Application\Port\HttpRequestHandlerInterface;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;
use Override;

final readonly class Nip11HttpHandler implements HttpRequestHandlerInterface
{
    private const int HTTP_OK = 200;

    private const string CONTENT_TYPE = 'application/nostr+json';

    private const int ENCODING_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private Nip11InfoProviderInterface $infoProvider,
    ) {
    }

    #[Override]
    public function handleHttpRequest(HttpRequestContext $request): ?HttpResponsePayload
    {
        if (!$this->negotiatesRelayInformation($request)) {
            return null;
        }

        return new HttpResponsePayload(
            self::HTTP_OK,
            ['content-type' => self::CONTENT_TYPE],
            json_encode($this->infoProvider->getNip11Info()->toArray(), self::ENCODING_FLAGS),
        );
    }

    private function negotiatesRelayInformation(HttpRequestContext $request): bool
    {
        return 'GET' === $request->getMethod()
            && '/' === $request->getPath()
            && str_contains($request->getHeader('accept') ?? '', self::CONTENT_TYPE);
    }
}
