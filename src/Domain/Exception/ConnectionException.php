<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Exception;

use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Throwable;

final class ConnectionException extends RelayException
{
    public function __construct(
        string $message = '',
        ?Throwable $previous = null,
        private readonly ?IpAddress $ipAddress = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getIpAddress(): ?IpAddress
    {
        return $this->ipAddress;
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

    public static function connectionLimitReached(int $maxConnections): self
    {
        return new self(message: 'Connection limit reached: '.$maxConnections);
    }

    public static function peerDisconnected(?Throwable $previous = null): self
    {
        return new self(
            message: 'Peer disconnected'.($previous ? ': '.$previous->getMessage() : ''),
            previous: $previous,
        );
    }
}
