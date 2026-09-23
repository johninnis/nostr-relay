<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Application\Service\ClientMessengerInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Override;

final class RecordingClientMessenger implements ClientMessengerInterface
{
    /** @var list<RelayMessage> */
    private array $sent = [];

    #[Override]
    public function send(RelayClient $client, RelayMessage $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * @return list<RelayMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
