<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Exception\ConnectionException;
use Override;

/**
 * A transport-free client connection that records every frame the relay sends it and,
 * after a bounded number of successful sends, fails like a peer that dropped mid-session.
 * The failing send raises the same ConnectionException the real websocket adapter raises,
 * so the relay's send-failure path is exercised without a socket.
 */
final class RecordingClientConnection implements ClientConnectionInterface
{
    /** @var list<string> */
    private array $sentFrames = [];
    private int $sendCount = 0;

    public function __construct(
        private readonly int $failAfterSends = PHP_INT_MAX,
    ) {
    }

    #[Override]
    public function sendText(string $text): void
    {
        if ($this->sendCount >= $this->failAfterSends) {
            throw ConnectionException::peerDisconnected();
        }

        ++$this->sendCount;
        $this->sentFrames[] = $text;
    }

    /**
     * @return list<string>
     */
    public function sentFrames(): array
    {
        return $this->sentFrames;
    }
}
