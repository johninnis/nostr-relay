<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Domain\Entity;

use Innis\Nostr\Core\Domain\Entity\SubscriptionCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;

final class RelayClient
{
    public function __construct(
        private readonly ClientId $id,
        private readonly ClientConnectionInterface $connection,
        private readonly ConnectionInfo $connectionInfo,
        private readonly SubscriptionLookupInterface $subscriptionLookup,
    ) {
    }

    public function getId(): ClientId
    {
        return $this->id;
    }

    public function getConnectionInfo(): ConnectionInfo
    {
        return $this->connectionInfo;
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->subscriptionLookup->getSubscriptionsForClient($this->id);
    }

    public function getSubscriptionCount(): int
    {
        return $this->subscriptionLookup->getSubscriptionCountForClient($this->id);
    }

    public function send(RelayMessage $message): void
    {
        $this->connection->sendText($message->toJson());
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
