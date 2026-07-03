<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\RandomBytesGeneratorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemoryAuthenticationRegistryTest extends TestCase
{
    private InMemoryAuthenticationRegistry $authenticationRegistry;
    private ClientId $clientId;

    protected function setUp(): void
    {
        $this->authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $this->clientId = ClientId::fromString('client-1');
    }

    public function testGenerateChallengeReturnsNonEmptyString(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->clientId);

        $this->assertNotEmpty($challenge);
        $this->assertSame(32, strlen($challenge));
    }

    public function testChallengeIsDerivedFromTheInjectedRandomBytesGenerator(): void
    {
        $generator = $this->createStub(RandomBytesGeneratorInterface::class);
        $generator->method('bytes')->willReturn(str_repeat("\x01", 16));
        $authenticationRegistry = new InMemoryAuthenticationRegistry($generator);

        $challenge = $authenticationRegistry->getOrCreateChallenge($this->clientId);

        $this->assertSame(str_repeat('01', 16), $challenge);
    }

    public function testGetChallengeReturnsStoredChallenge(): void
    {
        $challenge = $this->authenticationRegistry->getOrCreateChallenge($this->clientId);

        $this->assertSame($challenge, $this->authenticationRegistry->getChallenge($this->clientId));
    }

    public function testGetChallengeReturnsNullForUnknownClient(): void
    {
        $this->assertNull($this->authenticationRegistry->getChallenge(ClientId::fromString('unknown')));
    }

    public function testIsAuthenticatedReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->clientId));
    }

    public function testAuthenticateMarksClientAsAuthenticated(): void
    {
        $pubkey = self::createPubkey();

        $this->authenticationRegistry->authenticate($this->clientId, $pubkey);

        $this->assertTrue($this->authenticationRegistry->isAuthenticated($this->clientId));
    }

    public function testIsAuthenticatedAsReturnsTrueForMatchingPubkey(): void
    {
        $pubkey = self::createPubkey();

        $this->authenticationRegistry->authenticate($this->clientId, $pubkey);

        $this->assertTrue($this->authenticationRegistry->isAuthenticatedAs($this->clientId, $pubkey));
    }

    public function testIsAuthenticatedAsReturnsFalseForDifferentPubkey(): void
    {
        $pubkey1 = self::createPubkey();
        $pubkey2 = PublicKey::tryFromHex(str_repeat('bb', 32)) ?? throw new RuntimeException('Invalid pubkey');

        $this->authenticationRegistry->authenticate($this->clientId, $pubkey1);

        $this->assertFalse($this->authenticationRegistry->isAuthenticatedAs($this->clientId, $pubkey2));
    }

    public function testAuthenticateDoesNotDuplicatePubkeys(): void
    {
        $pubkey = self::createPubkey();

        $this->authenticationRegistry->authenticate($this->clientId, $pubkey);
        $this->authenticationRegistry->authenticate($this->clientId, $pubkey);

        $this->assertCount(1, $this->authenticationRegistry->getAuthenticatedPubkeys($this->clientId));
    }

    public function testGetAuthenticatedPubkeysReturnsEmptyForUnknownClient(): void
    {
        $this->assertCount(0, $this->authenticationRegistry->getAuthenticatedPubkeys(ClientId::fromString('unknown')));
    }

    public function testRemoveClientClearsAuthStateAndChallenge(): void
    {
        $pubkey = self::createPubkey();
        $this->authenticationRegistry->getOrCreateChallenge($this->clientId);
        $this->authenticationRegistry->authenticate($this->clientId, $pubkey);

        $this->authenticationRegistry->removeClient($this->clientId);

        $this->assertFalse($this->authenticationRegistry->isAuthenticated($this->clientId));
        $this->assertNull($this->authenticationRegistry->getChallenge($this->clientId));
    }

    private static function createPubkey(): PublicKey
    {
        return PublicKey::tryFromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
    }
}
