<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Service\Nip42ValidatorInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Failure\Nip42ValidationFailure;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\AuthEventVerifier;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\Nip42Handshake;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class Nip42HandshakeTest extends TestCase
{
    private InMemoryAuthenticationRegistry $authRegistry;
    private Nip42ValidatorInterface&Stub $validator;
    private RelayPolicyInterface&Stub $policy;
    private Nip42Handshake $handshake;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->authRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $this->validator = $this->createStub(Nip42ValidatorInterface::class);
        $this->policy = $this->createStub(RelayPolicyInterface::class);

        $config = $this->createStub(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $this->handshake = new Nip42Handshake(
            $this->authRegistry,
            new AuthEventVerifier($config, $this->policy, $this->validator),
            new AuthChallengeIssuer($this->authRegistry),
        );

        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    public function testAClaimNamingNoStoredChallengeIsAnsweredWithAFreshChallenge(): void
    {
        $claim = $this->handshake->verifyClaim($this->client, $this->authEvent());

        $this->assertInstanceOf(AuthMessage::class, $claim);
        $this->assertNotNull($this->authRegistry->getChallenge($this->client->getId()));
    }

    public function testAFailedClaimIsAnsweredWithARejection(): void
    {
        $this->authRegistry->getOrCreateChallenge($this->client->getId());
        $this->validator->method('validate')->willReturn(Nip42ValidationFailure::ChallengeMismatch);

        $claim = $this->handshake->verifyClaim($this->client, $this->authEvent());

        $this->assertInstanceOf(PolicyRejection::class, $claim);
        $this->assertSame('auth-required: Challenge does not match the one issued', $claim->toWireReason());
    }

    public function testAMatchingClaimPasses(): void
    {
        $this->authRegistry->getOrCreateChallenge($this->client->getId());
        $this->validator->method('validate')->willReturn(null);

        $this->assertNull($this->handshake->verifyClaim($this->client, $this->authEvent()));
    }

    public function testAuthenticateRefusesAnIdentityThePolicyRejects(): void
    {
        $this->policy->method('allowsAuthentication')->willReturn(PolicyRejection::restricted('tenants only'));

        $result = $this->handshake->authenticate($this->client, $this->authEvent());

        $this->assertInstanceOf(PolicyRejection::class, $result);
        $this->assertFalse($this->authRegistry->isAuthenticated($this->client->getId()));
    }

    public function testAuthenticateRegistersAndReturnsThePubkey(): void
    {
        $this->policy->method('allowsAuthentication')->willReturn(null);
        $event = $this->authEvent();

        $result = $this->handshake->authenticate($this->client, $event);

        $this->assertNotInstanceOf(PolicyRejection::class, $result);
        $this->assertTrue($event->getPubkey()->equals($result));
        $this->assertTrue($this->authRegistry->isAuthenticatedAs($this->client->getId(), $event->getPubkey()));
    }

    private function authEvent(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::CLIENT_AUTH),
            EventContent::fromString(''),
            new TagCollection(),
        ));
    }
}
