<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Application\Service;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;

final class AuthenticationManager
{
    private array $authenticatedPubkeys = [];
    private array $challenges = [];

    public function generateChallenge(ClientId $clientId): string
    {
        $key = $clientId->toString();

        if (isset($this->challenges[$key])) {
            return $this->challenges[$key];
        }

        $challenge = bin2hex(random_bytes(16));
        $this->challenges[$key] = $challenge;

        return $challenge;
    }

    public function getChallenge(ClientId $clientId): ?string
    {
        return $this->challenges[$clientId->toString()] ?? null;
    }

    public function authenticate(ClientId $clientId, PublicKey $pubkey): void
    {
        $key = $clientId->toString();
        unset($this->challenges[$key]);

        if (!isset($this->authenticatedPubkeys[$key])) {
            $this->authenticatedPubkeys[$key] = [];
        }

        foreach ($this->authenticatedPubkeys[$key] as $existing) {
            if ($existing->equals($pubkey)) {
                return;
            }
        }

        $this->authenticatedPubkeys[$key][] = $pubkey;
    }

    public function isAuthenticated(ClientId $clientId): bool
    {
        return !empty($this->authenticatedPubkeys[$clientId->toString()]);
    }

    public function getAuthenticatedPubkeys(ClientId $clientId): array
    {
        return $this->authenticatedPubkeys[$clientId->toString()] ?? [];
    }

    public function isAuthenticatedAs(ClientId $clientId, PublicKey $pubkey): bool
    {
        foreach ($this->getAuthenticatedPubkeys($clientId) as $existing) {
            if ($existing->equals($pubkey)) {
                return true;
            }
        }

        return false;
    }

    public function removeClient(ClientId $clientId): void
    {
        $key = $clientId->toString();
        unset($this->authenticatedPubkeys[$key], $this->challenges[$key]);
    }
}
