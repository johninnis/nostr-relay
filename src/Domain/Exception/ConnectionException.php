<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Exception;

use Throwable;

final class ConnectionException extends RelayException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?string $ipAddress = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public static function maxConnectionsReached(string $ipAddress): self
    {
        return new self(
            message: 'Max connections reached for '.$ipAddress,
            ipAddress: $ipAddress,
        );
    }

    public static function bindFailed(string $host, int $port, ?Throwable $previous = null): self
    {
        return new self(
            message: "Failed to bind to {$host}:{$port}".($previous ? ' - '.$previous->getMessage() : ''),
            previous: $previous,
        );
    }
}
