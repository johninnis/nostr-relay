<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Exception;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Throwable;

final class ConnectionException extends RelayException
{
    // Deliberate: mirrors the PHP exception constructor (message, code, previous) plus the IpAddress context this fault carries; the shape is fixed by Throwable, not a decomposable design.
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?IpAddress $ipAddress = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getIpAddress(): ?IpAddress
    {
        return $this->ipAddress;
    }

    public static function maxConnectionsReached(IpAddress $ipAddress): self
    {
        return new self(
            message: 'Max connections reached for '.$ipAddress,
            ipAddress: $ipAddress,
        );
    }

    public static function ipBlocked(IpAddress $ipAddress): self
    {
        return new self(
            message: 'Connection rejected for '.$ipAddress,
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

    public static function invalidPort(int $port): self
    {
        return new self(
            message: "Invalid port {$port}: must be between 0 and 65535",
        );
    }

    public static function peerDisconnected(?Throwable $previous = null): self
    {
        return new self(
            message: 'Peer disconnected'.($previous ? ': '.$previous->getMessage() : ''),
            previous: $previous,
        );
    }
}
