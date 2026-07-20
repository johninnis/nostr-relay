<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Infrastructure\Http;

use Innis\Nostr\Relay\Application\DTO\HttpRequestContext;
use Innis\Nostr\Relay\Application\DTO\HttpResponsePayload;
use Innis\Nostr\Relay\Application\Port\HttpRequestHandlerInterface;
use Override;

final readonly class ChainedHttpHandler implements HttpRequestHandlerInterface
{
    /** @var list<HttpRequestHandlerInterface> */
    private array $handlers;

    public function __construct(HttpRequestHandlerInterface ...$handlers)
    {
        $this->handlers = array_values($handlers);
    }

    #[Override]
    public function handleHttpRequest(HttpRequestContext $request): ?HttpResponsePayload
    {
        foreach ($this->handlers as $handler) {
            $response = $handler->handleHttpRequest($request);

            if (null !== $response) {
                return $response;
            }
        }

        return null;
    }
}
