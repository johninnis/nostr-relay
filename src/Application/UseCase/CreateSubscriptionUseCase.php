<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionActivator;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use InvalidArgumentException;
use Override;

final readonly class CreateSubscriptionUseCase implements ClientVerbHandlerInterface
{
    public function __construct(
        private SubscriptionActivator $activator,
    ) {
    }

    /**
     * @return class-string<ClientMessage>
     */
    #[Override]
    public function handledMessageType(): string
    {
        return ReqMessage::class;
    }

    /**
     * @return list<RelayMessage>
     */
    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        return match (true) {
            $message instanceof ReqMessage => $this->execute($client, $message->getSubscriptionId(), $message->getFilters()),
            default => throw new InvalidArgumentException('CreateSubscriptionUseCase cannot handle '.$message::class),
        };
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, SubscriptionId $subscriptionId, FilterCollection $filters): array
    {
        return $this->activator->activate($client, $subscriptionId, $filters);
    }
}
