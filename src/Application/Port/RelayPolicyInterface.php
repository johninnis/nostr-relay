<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;

interface RelayPolicyInterface
{
    public function allowEventSubmission(RelayClient $client, Event $event): void;

    public function allowSubscription(RelayClient $client, array $filters): void;

    public function filterForClient(RelayClient $client, array $filters): array;

    public function canClientReceiveEvent(RelayClient $client, Event $event): bool;

    public function getMaxSubscriptionsPerClient(): int;

    public function isRateLimitExempt(RelayClient $client): bool;
}
