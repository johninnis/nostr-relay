<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

final readonly class ReadScopeGate
{
    public function __construct(
        private RelayPolicyInterface $policy,
        private AuthenticatedSessionsInterface $authenticatedSessions,
    ) {
    }

    // Deliberate: the read carries the keys the connection has authenticated as, so the store confines gift wraps to them after every policy, whatever scope the policy grants — see ADR-0036
    public function admit(RelayClient $client, FilterCollection $filters, int $heldCount): PolicyRejection|AdmittedRead
    {
        return $this->policy->allowSubscription($client, $filters, $heldCount)
            ?? new AdmittedRead(
                $this->policy->filterForClient($client, $filters),
                new ActingKeys($this->authenticatedSessions->getAuthenticatedPubkeys($client->getId())),
            );
    }
}
