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
use Innis\Nostr\Relay\Application\Service\EventValidityGate;
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\TestCase;

final class EventValidityGateTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testAValidUnexpiredEventIsAdmitted(): void
    {
        $this->assertNull($this->gate()->admit($this->eventExpiringAt(null)));
    }

    public function testAnAlreadyExpiredEventIsRefusedAsInvalid(): void
    {
        $outcome = $this->gate()->admit($this->eventExpiringAt(self::NOW - 1));

        $this->assertInstanceOf(PolicyRejection::class, $outcome);
        $this->assertSame('invalid: event has expired', $outcome->toWireReason());
    }

    public function testTheValidatorIsAskedAtTheInjectedInstant(): void
    {
        $validator = $this->createMock(EventValidatorInterface::class);
        $validator->expects($this->once())->method('validateEvent')->with(
            $this->anything(),
            $this->callback(static fn (Timestamp $reference): bool => self::NOW === $reference->toInt()),
        );

        $this->gate($validator)->admit($this->eventExpiringAt(null));
    }

    public function testOneInstantJudgesBothValidityAndExpiry(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturnOnConsecutiveCalls(
            Timestamp::fromInt(self::NOW),
            Timestamp::fromInt(self::NOW + 3600),
        );

        $gate = new EventValidityGate($this->createStub(EventValidatorInterface::class), $clock);

        $this->assertNull($gate->admit($this->eventExpiringAt(self::NOW + 1)));
    }

    private function gate(?EventValidatorInterface $validator = null): EventValidityGate
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(self::NOW));

        return new EventValidityGate($validator ?? $this->createStub(EventValidatorInterface::class), $clock);
    }

    private function eventExpiringAt(?int $expiration): Event
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
