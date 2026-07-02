<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Domain\Enum\AuthRejection;
use PHPUnit\Framework\TestCase;

final class AuthEventVerifierTest extends TestCase
{
    private const string RELAY_URL = 'wss://relay.example.com';
    private const string CHALLENGE = 'the-challenge';

    private SignatureServiceInterface $signatureService;
    private KeyPair $keyPair;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $this->keyPair = KeyPair::generate($this->signatureService);
    }

    public function testReturnsNullWhenEveryConditionHolds(): void
    {
        $verifier = $this->verifier(allowsAuthentication: true, now: time());

        $this->assertNull($verifier->verify($this->authEvent(self::CHALLENGE, self::RELAY_URL, time()), self::CHALLENGE));
    }

    public function testRejectsMismatchedChallenge(): void
    {
        $verifier = $this->verifier(allowsAuthentication: true, now: time());

        $this->assertSame(
            AuthRejection::InvalidChallenge,
            $verifier->verify($this->authEvent('wrong', self::RELAY_URL, time()), self::CHALLENGE),
        );
    }

    public function testRejectsMismatchedRelayUrl(): void
    {
        $verifier = $this->verifier(allowsAuthentication: true, now: time());

        $this->assertSame(
            AuthRejection::InvalidRelayUrl,
            $verifier->verify($this->authEvent(self::CHALLENGE, 'wss://wrong.example.com', time()), self::CHALLENGE),
        );
    }

    public function testRejectsTimestampBeyondToleranceOfInjectedClock(): void
    {
        $verifier = $this->verifier(allowsAuthentication: true, now: time() + 601);

        $this->assertSame(
            AuthRejection::TimestampOutOfRange,
            $verifier->verify($this->authEvent(self::CHALLENGE, self::RELAY_URL, time()), self::CHALLENGE),
        );
    }

    public function testRejectsPubkeyDisallowedByPolicy(): void
    {
        $verifier = $this->verifier(allowsAuthentication: false, now: time());

        $this->assertSame(
            AuthRejection::Restricted,
            $verifier->verify($this->authEvent(self::CHALLENGE, self::RELAY_URL, time()), self::CHALLENGE),
        );
    }

    private function verifier(bool $allowsAuthentication, int $now): AuthEventVerifier
    {
        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString(self::RELAY_URL));

        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn($allowsAuthentication);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        return new AuthEventVerifier($config, $policy, $clock);
    }

    private function authEvent(string $challenge, string $relayUrl, int $timestamp): Event
    {
        return (new Event(
            $this->keyPair->getPublicKey(),
            Timestamp::fromInt($timestamp),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            new TagCollection([
                Tag::fromArray(['relay', $relayUrl]),
                Tag::fromArray(['challenge', $challenge]),
            ]),
            EventContent::fromString(''),
        ))->sign($this->keyPair, $this->signatureService);
    }
}
