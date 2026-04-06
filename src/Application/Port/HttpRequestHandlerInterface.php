<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Relay\Application\DTO\HttpRequestContext;
use Innis\Nostr\Relay\Application\DTO\HttpResponsePayload;

interface HttpRequestHandlerInterface
{
    public function handleHttpRequest(HttpRequestContext $request): ?HttpResponsePayload;
}
