<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Enum\ClientMessageType;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuthUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use LogicException;
use Override;

final readonly class AuthMessageHandler implements ClientMessageHandlerInterface
{
    public function __construct(
        private ProcessAuthUseCase $useCase,
    ) {
    }

    #[Override]
    public function handles(): ClientMessageType
    {
        return ClientMessageType::Auth;
    }

    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        // The dispatcher only routes a message to the handler registered for its type, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly, not silently.
        if (!$message instanceof AuthMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

        return $this->useCase->execute($client, $message->getEvent());
    }
}
