<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\PublishingGate;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PublishingGateTest extends TestCase
{
    public function testAProtectedEventFromAnUnauthenticatedConnectionIsAnsweredAuthRequired(): void
    {
        $rejection = $this->admit($this->protectedEventBy(KeyMother::alicePublicKey()))->getRejection();

        $this->assertNotNull($rejection);
        $this->assertSame('auth-required: this event may only be published by its author', $rejection->toWireReason());
    }

    public function testAProtectedEventFromAConnectionAuthenticatedAsAnotherKeyIsRestricted(): void
    {
        $rejection = $this->admit($this->protectedEventBy(KeyMother::alicePublicKey()), KeyMother::bobPublicKey())->getRejection();

        $this->assertNotNull($rejection);
        $this->assertSame('restricted: this event may only be published by its author', $rejection->toWireReason());
    }

    public function testAProtectedEventFromItsAuthenticatedAuthorIsAdmitted(): void
    {
        $answer = $this->admit($this->protectedEventBy(KeyMother::alicePublicKey()), KeyMother::alicePublicKey());

        $this->assertTrue($answer->isAdmitted());
    }

    public function testAnEventWhoseDashTagCarriesAValueIsNotProtected(): void
    {
        $event = $this->eventBy(KeyMother::alicePublicKey(), [Tag::fromArray([TagType::PROTECTED, 'x'])]);

        $this->assertTrue($this->admit($event)->isAdmitted());
    }

    public function testAPolicyRefusalIsAnsweredBeforeTheAuthorRule(): void
    {
        $rejection = $this->admit(
            $this->protectedEventBy(KeyMother::alicePublicKey()),
            policyRejection: PolicyRejection::blocked('event too large'),
        )->getRejection();

        $this->assertNotNull($rejection);
        $this->assertSame('blocked: event too large', $rejection->toWireReason());
    }

    public function testAnAdmittedEventCarriesThePolicysChallengeOffer(): void
    {
        $answer = $this->admit($this->eventBy(KeyMother::alicePublicKey(), []), offersChallenge: true);

        $this->assertTrue($answer->isAdmitted());
        $this->assertInstanceOf(AuthMessage::class, $answer->getChallenge());
    }

    private function admit(
        Event $event,
        ?PublicKey $authenticatedAs = null,
        ?PolicyRejection $policyRejection = null,
        bool $offersChallenge = false,
    ): PublishingAnswer {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowEventSubmission')->willReturn($policyRejection);
        $policy->method('offersAuthChallenge')->willReturn($offersChallenge);

        $sessions = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $client = $this->client();

        if (null !== $authenticatedAs) {
            $sessions->authenticate($client->getId(), $authenticatedAs);
        }

        return new PublishingGate($policy, $sessions, new AuthChallengeIssuer($sessions))->admit($client, $event);
    }

    private function client(): RelayClient
    {
        $registry = new InMemoryClientRegistry(
            $this->createStub(MetricsCollectorInterface::class),
            new NativeRandomBytesGenerator(),
            new NullLogger(),
        );

        return $registry->registerClient(
            $this->createStub(ClientConnectionInterface::class),
            new ConnectionInfo(IpAddress::fromString('127.0.0.1'), 'Test/1.0', Timestamp::now()),
        );
    }

    private function protectedEventBy(PublicKey $author): Event
    {
        return $this->eventBy($author, [Tag::fromArray([TagType::PROTECTED])]);
    }

    /**
     * @param list<Tag> $tags
     */
    private function eventBy(PublicKey $author, array $tags): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            $author,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello members of the secret group'),
            new TagCollection($tags),
        ));
    }
}
