<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Domain\Enum\AuthRejection;

final readonly class AuthEventVerifier
{
    private const int TIMESTAMP_TOLERANCE_SECONDS = 600;

    public function __construct(
        private RelayConfigInterface $config,
        private RelayPolicyInterface $policy,
        private ClockInterface $clock,
    ) {
    }

    public function verify(Event $event, string $challenge): ?AuthRejection
    {
        $challengeTags = $event->getTags()->getValuesByType(TagType::fromString('challenge'));
        if (empty($challengeTags) || reset($challengeTags) !== $challenge) {
            return AuthRejection::InvalidChallenge;
        }

        $relayTags = $event->getTags()->getValuesByType(TagType::fromString('relay'));
        if (empty($relayTags) || reset($relayTags) !== (string) $this->config->getRelayUrl()) {
            return AuthRejection::InvalidRelayUrl;
        }

        if ($this->clock->now()->differenceInSeconds($event->getCreatedAt()) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return AuthRejection::TimestampOutOfRange;
        }

        if (!$this->policy->allowsAuthentication($event->getPubkey())) {
            return AuthRejection::Restricted;
        }

        return null;
    }
}
