<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\UseCase;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagCollection;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\UseCase\ProcessAuth\ProcessAuthUseCase;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\Service\ClientConnectionInterface;
use Innis\Nostr\Relay\Domain\Service\SubscriptionLookupInterface;
use Innis\Nostr\Relay\Domain\ValueObject\ClientId;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessAuthUseCaseTest extends TestCase
{
    private AuthenticationManager $authManager;
    private ProcessAuthUseCase $useCase;
    private RelayClient $client;
    private ClientConnectionInterface&MockObject $connection;
    private KeyPair $keyPair;
    private SignatureServiceInterface $sigService;

    private function signatureService(): SignatureServiceInterface
    {
        return $this->sigService ??= Secp256k1SignatureAdapter::create();
    }

    protected function setUp(): void
    {
        $this->authManager = new AuthenticationManager();
        $this->keyPair = KeyPair::generate($this->signatureService());

        $config = $this->createMock(RelayConfigInterface::class);
        $config->method('getRelayUrl')->willReturn(RelayUrl::fromString('wss://relay.example.com'));

        $this->useCase = new ProcessAuthUseCase(
            $this->authManager,
            $config,
            new NullLogger(),
            $this->signatureService(),
        );

        $this->connection = $this->createMock(ClientConnectionInterface::class);
        $this->client = new RelayClient(
            ClientId::fromString('client-1'),
            $this->connection,
            new ConnectionInfo('127.0.0.1', 'Test/1.0', Timestamp::now()),
            $this->createMock(SubscriptionLookupInterface::class),
        );
    }

    public function testSuccessfulAuthentication(): void
    {
        $challenge = $this->authManager->generateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && true === $data[2];
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertTrue($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsWhenNoChallengeIssued(): void
    {
        $event = $this->createAuthEvent('some-challenge', 'wss://relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'no challenge');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsInvalidChallenge(): void
    {
        $this->authManager->generateChallenge($this->client->getId());
        $event = $this->createAuthEvent('wrong-challenge', 'wss://relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'invalid challenge');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsInvalidRelayUrl(): void
    {
        $challenge = $this->authManager->generateChallenge($this->client->getId());
        $event = $this->createAuthEvent($challenge, 'wss://wrong-relay.example.com');

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'invalid relay URL');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $challenge = $this->authManager->generateChallenge($this->client->getId());
        $event = $this->createAuthEventWithTimestamp($challenge, 'wss://relay.example.com', time() - 700);

        $this->connection->expects($this->once())->method('sendText')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                assert(is_array($data));

                return 'OK' === $data[0] && false === $data[2] && str_contains((string) $data[3], 'timestamp');
            }));

        $this->useCase->execute($this->client, $event);

        $this->assertFalse($this->authManager->isAuthenticated($this->client->getId()));
    }

    private function createAuthEvent(string $challenge, string $relayUrl): Event
    {
        return $this->createAuthEventWithTimestamp($challenge, $relayUrl, time());
    }

    private function createAuthEventWithTimestamp(string $challenge, string $relayUrl, int $timestamp): Event
    {
        return (new Event(
            $this->keyPair->getPublicKey(),
            Timestamp::fromInt($timestamp),
            EventKind::clientAuth(),
            new TagCollection([
                Tag::fromArray(['relay', $relayUrl]),
                Tag::fromArray(['challenge', $challenge]),
            ]),
            EventContent::fromString(''),
        ))->sign($this->keyPair, $this->signatureService());
    }
}
