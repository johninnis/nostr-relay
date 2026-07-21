<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Innis\Nostr\Relay\Application\Port\Nip11InfoProviderInterface;

final readonly class Nip11HttpHandler
{
    private const string CONTENT_TYPE = 'application/nostr+json';

    private const int ENCODING_FLAGS = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private Nip11InfoProviderInterface $infoProvider,
    ) {
    }

    public function handleRequest(Request $request): ?Response
    {
        if (!self::negotiatesRelayInformation($request)) {
            return null;
        }

        return new Response(
            HttpStatus::OK,
            ['content-type' => self::CONTENT_TYPE],
            json_encode($this->infoProvider->getNip11Info()->toArray(), self::ENCODING_FLAGS),
        );
    }

    private static function negotiatesRelayInformation(Request $request): bool
    {
        return 'GET' === $request->getMethod()
            && '/' === $request->getUri()->getPath()
            && self::acceptsRelayInformation($request->getHeader('accept') ?? '');
    }

    private static function acceptsRelayInformation(string $accept): bool
    {
        return array_any(
            explode(',', $accept),
            static fn (string $mediaRange): bool => self::selectsRelayInformation($mediaRange),
        );
    }

    private static function selectsRelayInformation(string $mediaRange): bool
    {
        $parameters = explode(';', $mediaRange);

        return self::CONTENT_TYPE === strtolower(trim($parameters[0]))
            && 0.0 < self::qualityOf(array_slice($parameters, 1));
    }

    /**
     * @param list<string> $parameters
     */
    private static function qualityOf(array $parameters): float
    {
        foreach ($parameters as $parameter) {
            [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');

            if ('q' === strtolower(trim($name))) {
                return (float) trim($value);
            }
        }

        return 1.0;
    }
}
