<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EventValidatorInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Port\ClientConnectionInterface;
use Innis\Nostr\Relay\Application\Port\MetricsCollectorInterface;
use Innis\Nostr\Relay\Application\Port\RateLimiterInterface;
use Innis\Nostr\Relay\Application\Port\RelayPolicyInterface;
use Innis\Nostr\Relay\Application\Service\AuthChallengeIssuer;
use Innis\Nostr\Relay\Application\Service\EventAdmission;
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\InMemoryClientRegistry;
use Innis\Nostr\Relay\Application\Service\PublishingGate;
use Innis\Nostr\Relay\Application\Service\RateLimitGate;
use Innis\Nostr\Relay\Domain\Entity\RelayClient;
use Innis\Nostr\Relay\Domain\ValueObject\ConnectionInfo;
use Innis\Nostr\Relay\Domain\ValueObject\IpAddress;
use Innis\Nostr\Relay\Domain\ValueObject\PublishingAnswer;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EventAdmissionTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAnEventWithNoExpiryIsAdmitted(): void
    {
        $this->assertTrue($this->admit($this->event(null))->isAdmitted());
    }

    public function testAnAlreadyExpiredEventIsRefusedAsInvalid(): void
    {
        $rejection = $this->admit($this->event(self::NOW - 1))->getRejection();

        $this->assertNotNull($rejection);
        $this->assertSame('invalid: event has expired', $rejection->toWireReason());
    }

    public function testAnEventIsRefusedAtTheExpiryInstant(): void
    {
        $this->assertFalse($this->admit($this->event(self::NOW))->isAdmitted());
    }

    public function testAnEventExpiringOneSecondLaterIsAdmitted(): void
    {
        $this->assertTrue($this->admit($this->event(self::NOW + 1))->isAdmitted());
    }

    public function testExpiryIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $this->assertTrue($this->admit($this->event(self::NOW + 1), now: self::NOW - 3600)->isAdmitted());
    }

    public function testValidityIsJudgedAgainstTheInjectedClockNotTheWallClock(): void
    {
        $validator = $this->createMock(EventValidatorInterface::class);
        $validator->expects($this->once())->method('validateEvent')->with(
            $this->anything(),
            $this->callback(static fn (Timestamp $reference): bool => self::NOW === $reference->toInt()),
        );

        $this->admit($this->event(null), validator: $validator);
    }

    private function admit(Event $event, int $now = self::NOW, ?EventValidatorInterface $validator = null): PublishingAnswer
    {
        $policy = $this->createStub(RelayPolicyInterface::class);
        $policy->method('allowEventSubmission')->willReturn(null);
        $policy->method('offersAuthChallenge')->willReturn(false);

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('tryConsume')->willReturn(true);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($now));

        $sessions = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());

        $admission = new EventAdmission(
            new RateLimitGate($rateLimiter, $policy),
            new EventValidityGate($validator ?? $this->createStub(EventValidatorInterface::class), $clock),
            new PublishingGate($policy, $sessions, new AuthChallengeIssuer($sessions)),
        );

        return $admission->admit($this->client(), $event);
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

    private function event(?int $expiration): Event
    {
        $tags = null === $expiration ? [] : [Tag::tryFromArray(['expiration', (string) $expiration])];

        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection($tags),
            Timestamp::fromInt(self::NOW - 60),
        ));
    }
}
