<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\DTO;

final readonly class HttpRequestContext
{
    public function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private string $body,
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHeader(string $name): ?string
    {
        $normalised = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $normalised) {
                return $value;
            }
        }

        return null;
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
