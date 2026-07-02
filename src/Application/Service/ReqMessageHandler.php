<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Relay\Application\UseCase\CreateSubscriptionUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use LogicException;
use Override;

final readonly class ReqMessageHandler implements ClientMessageHandlerInterface
{
    public function __construct(
        private CreateSubscriptionUseCase $useCase,
    ) {
    }

    #[Override]
    public function handles(): string
    {
        return ReqMessage::class;
    }

    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        // The dispatcher only routes a message to the handler registered for its class, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly, not silently.
        if (!$message instanceof ReqMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

        return $this->useCase->execute($client, $message->getSubscriptionId(), $message->getFilters());
    }
}
