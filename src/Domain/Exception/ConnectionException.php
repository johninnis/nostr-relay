<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Exception;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Throwable;

final class ConnectionException extends RelayException
{
    // Deliberate: mirrors the Throwable constructor plus one IpAddress context field — see ADR-0010
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

    public static function malformedIpAddress(string $rawAddress): self
    {
        return new self(message: 'Malformed client address: '.$rawAddress);
    }

    public static function peerDisconnected(?Throwable $previous = null): self
    {
        return new self(
            message: 'Peer disconnected'.($previous ? ': '.$previous->getMessage() : ''),
            previous: $previous,
        );
    }
}
