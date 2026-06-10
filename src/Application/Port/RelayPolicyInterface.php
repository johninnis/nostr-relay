<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;

interface RelayPolicyInterface
{
    public function allowEventSubmission(RelayClient $client, Event $event): void;

    public function allowSubscription(RelayClient $client, array $filters): void;

    public function allowsAuthentication(PublicKey $pubkey): bool;

    public function filterForClient(RelayClient $client, array $filters): ScopedFilters;

    public function canClientReceiveEvent(RelayClient $client, Event $event): bool;

    public function isRateLimitExempt(RelayClient $client): bool;
}
