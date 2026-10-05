<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\UseCase;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Relay\Application\Service\ClientVerbHandlerInterface;
use Innis\Nostr\Relay\Application\Service\SubscriptionRegistryInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use InvalidArgumentException;
use Override;

final readonly class CloseSubscriptionUseCase implements ClientVerbHandlerInterface
{
    public function __construct(
        private SubscriptionRegistryInterface $subscriptionRegistry,
    ) {
    }

    /**
     * @return class-string<ClientMessage>
     */
    #[Override]
    public function handledMessageType(): string
    {
        return CloseMessage::class;
    }

    /**
     * @return list<RelayMessage>
     */
    #[Override]
    public function handle(RelayClient $client, ClientMessage $message): array
    {
        return match (true) {
            $message instanceof CloseMessage => $this->execute($client, $message->getSubscriptionId()),
            default => throw new InvalidArgumentException('CloseSubscriptionUseCase cannot handle '.$message::class),
        };
    }

    /**
     * @return list<RelayMessage>
     */
    public function execute(RelayClient $client, SubscriptionId $subscriptionId): array
    {
        $this->subscriptionRegistry->removeSubscription($client->getId(), $subscriptionId);

        return [];
    }
}
