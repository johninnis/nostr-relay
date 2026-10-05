<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Service\Nip42ValidatorInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Failure\Nip42ValidationFailure;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayChallenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;

final class AuthEventVerifierTest extends TestCase
{
    private const string CHALLENGE = 'the-challenge';

    public function testReturnsNullWhenTheProtocolAndThePolicyBothAccept(): void
    {
        $verifier = $this->verifier(protocolFailure: null, allowsAuthentication: true);

        $this->assertNull($verifier->verifyClaim($this->authEvent(), self::challenge()) ?? $verifier->verifyIdentity($this->authEvent()));
    }

    public function testAProtocolFailureIsReportedAsAuthRequiredCarryingItsReason(): void
    {
        $verifier = $this->verifier(protocolFailure: Nip42ValidationFailure::ChallengeMismatch, allowsAuthentication: true);

        $rejection = $verifier->verifyClaim($this->authEvent(), self::challenge()) ?? $verifier->verifyIdentity($this->authEvent());

        $this->assertInstanceOf(PolicyRejection::class, $rejection);
        $this->assertSame('auth-required: Challenge does not match the one issued', $rejection->toWireReason());
    }

    public function testAProtocolFailureIsReportedInTheProtocolsOwnWords(): void
    {
        $verifier = $this->verifier(protocolFailure: Nip42ValidationFailure::RelayMismatch, allowsAuthentication: false);

        $rejection = $verifier->verifyClaim($this->authEvent(), self::challenge()) ?? $verifier->verifyIdentity($this->authEvent());

        $this->assertInstanceOf(PolicyRejection::class, $rejection);
        $this->assertSame('auth-required: Relay URL does not match this relay', $rejection->toWireReason());
    }

    public function testThePolicysOwnRefusalIsPassedThroughUnchanged(): void
    {
        $verifier = $this->verifier(protocolFailure: null, allowsAuthentication: false);

        $rejection = $verifier->verifyClaim($this->authEvent(), self::challenge()) ?? $verifier->verifyIdentity($this->authEvent());

        $this->assertInstanceOf(PolicyRejection::class, $rejection);
        $this->assertSame('restricted: not a tenant here', $rejection->toWireReason());
    }

    public function testTheConfiguredRelayUrlAndChallengeReachTheValidator(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        $event = $this->authEvent();

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn($relayUrl);
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn(null);
        $validator = $this->createMock(Nip42ValidatorInterface::class);
        $validator->expects($this->once())->method('validate')->with($event, new RelayChallenge($relayUrl, self::challenge()))->willReturn(null);

        $this->assertNull(new AuthEventVerifier($config, $policy, $validator)->verifyClaim($event, self::challenge()));
    }

    private static function challenge(): Challenge
    {
        return Challenge::fromString(self::CHALLENGE);
    }

    private function verifier(?Nip42ValidationFailure $protocolFailure, bool $allowsAuthentication): AuthEventVerifier
    {
        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::tryFromString('wss://relay.example.com'));
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowsAuthentication')->willReturn($allowsAuthentication ? null : PolicyRejection::restricted('not a tenant here'));
        $validator = $this->createStub(Nip42ValidatorInterface::class);
        $validator->method('validate')->willReturn($protocolFailure);

        return new AuthEventVerifier($config, $policy, $validator);
    }

    private function authEvent(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection([]),
        ));
    }
}
