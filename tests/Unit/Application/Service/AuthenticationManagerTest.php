<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthenticationManagerTest extends TestCase
{
    private AuthenticationManager $authManager;
    private ClientId $clientId;

    protected function setUp(): void
    {
        $this->authManager = new AuthenticationManager();
        $this->clientId = ClientId::fromString('client-1');
    }

    public function testGenerateChallengeReturnsNonEmptyString(): void
    {
        $challenge = $this->authManager->generateChallenge($this->clientId);

        $this->assertNotEmpty($challenge);
        $this->assertSame(32, strlen($challenge));
    }

    public function testGetChallengeReturnsStoredChallenge(): void
    {
        $challenge = $this->authManager->generateChallenge($this->clientId);

        $this->assertSame($challenge, $this->authManager->getChallenge($this->clientId));
    }

    public function testGetChallengeReturnsNullForUnknownClient(): void
    {
        $this->assertNull($this->authManager->getChallenge(ClientId::fromString('unknown')));
    }

    public function testIsAuthenticatedReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->authManager->isAuthenticated($this->clientId));
    }

    public function testAuthenticateMarksClientAsAuthenticated(): void
    {
        $pubkey = self::createPubkey();

        $this->authManager->authenticate($this->clientId, $pubkey);

        $this->assertTrue($this->authManager->isAuthenticated($this->clientId));
    }

    public function testIsAuthenticatedAsReturnsTrueForMatchingPubkey(): void
    {
        $pubkey = self::createPubkey();

        $this->authManager->authenticate($this->clientId, $pubkey);

        $this->assertTrue($this->authManager->isAuthenticatedAs($this->clientId, $pubkey));
    }

    public function testIsAuthenticatedAsReturnsFalseForDifferentPubkey(): void
    {
        $pubkey1 = self::createPubkey();
        $pubkey2 = PublicKey::fromHex(str_repeat('bb', 32)) ?? throw new RuntimeException('Invalid pubkey');

        $this->authManager->authenticate($this->clientId, $pubkey1);

        $this->assertFalse($this->authManager->isAuthenticatedAs($this->clientId, $pubkey2));
    }

    public function testAuthenticateDoesNotDuplicatePubkeys(): void
    {
        $pubkey = self::createPubkey();

        $this->authManager->authenticate($this->clientId, $pubkey);
        $this->authManager->authenticate($this->clientId, $pubkey);

        $this->assertCount(1, $this->authManager->getAuthenticatedPubkeys($this->clientId));
    }

    public function testGetAuthenticatedPubkeysReturnsEmptyForUnknownClient(): void
    {
        $this->assertSame([], $this->authManager->getAuthenticatedPubkeys(ClientId::fromString('unknown')));
    }

    public function testRemoveClientClearsAuthStateAndChallenge(): void
    {
        $pubkey = self::createPubkey();
        $this->authManager->generateChallenge($this->clientId);
        $this->authManager->authenticate($this->clientId, $pubkey);

        $this->authManager->removeClient($this->clientId);

        $this->assertFalse($this->authManager->isAuthenticated($this->clientId));
        $this->assertNull($this->authManager->getChallenge($this->clientId));
    }

    private static function createPubkey(): PublicKey
    {
        return PublicKey::fromHex(str_repeat('aa', 32)) ?? throw new RuntimeException('Invalid pubkey');
    }
}
