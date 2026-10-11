<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;

final readonly class EventAcceptance
{
    public function __construct(
        private AcceptedEventPipeline $pipeline,
        private AuthChallengeIssuer $authChallengeIssuer,
    ) {
    }

    // Deliberate: the policy's challenge is issued only beside an OK true, so an event the store refuses as a duplicate is never answered behind a challenge offered for an accepted one — see ADR-0029
    /**
     * @return list<RelayMessage>
     */
    public function accept(RelayClient $client, Event $event, PublishingAnswer $answer): array
    {
        $acknowledgement = $this->pipeline->accept($client, $event);

        return $answer->offersChallenge() && $acknowledgement->isAccepted()
            ? [$this->authChallengeIssuer->issue($client->getId()), $acknowledgement]
            : [$acknowledgement];
    }
}
