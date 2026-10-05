<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;

final readonly class ClientVerbHandlers
{
    /**
     * @var array<class-string<ClientMessage>, ClientVerbHandlerInterface>
     */
    private array $handlers;

    public function __construct(ClientVerbHandlerInterface ...$handlers)
    {
        $map = [];
        foreach ($handlers as $handler) {
            $map[$handler->handledMessageType()] = $handler;
        }

        $this->handlers = $map;
    }

    public function handlerFor(ClientMessage $message): ?ClientVerbHandlerInterface
    {
        return $this->handlers[$message::class] ?? null;
    }
}
