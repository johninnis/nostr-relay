<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\DTO;

final readonly class HttpResponsePayload
{
    public function __construct(
        private int $statusCode,
        private array $headers,
        private string $body,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
