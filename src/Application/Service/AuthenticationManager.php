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
        $key = (string) $clientId;

        if (isset($this->challenges[$key])) {
            return $this->challenges[$key];
        }

        $challenge = bin2hex(random_bytes(16));
        $this->challenges[$key] = $challenge;

        return $challenge;
    }

    public function getChallenge(ClientId $clientId): ?string
    {
        return $this->challenges[(string) $clientId] ?? null;
    }

    public function authenticate(ClientId $clientId, PublicKey $pubkey): void
    {
        $key = (string) $clientId;
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
        return !empty($this->authenticatedPubkeys[(string) $clientId]);
    }

    public function getAuthenticatedPubkeys(ClientId $clientId): array
    {
        return $this->authenticatedPubkeys[(string) $clientId] ?? [];
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
        $key = (string) $clientId;
        unset($this->authenticatedPubkeys[$key], $this->challenges[$key]);
    }
}
