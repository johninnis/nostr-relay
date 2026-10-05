<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Relay\Domain\ValueObject\EncodedEvent;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use Innis\Nostr\Relay\Tests\Support\SubscriptionIdMother;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class EncodedEventTest extends TestCase
{
    public function testOfEncodesTheFieldsTheEventHolds(): void
    {
        $event = self::event();

        self::assertSame($event->toJson(), EncodedEvent::of($event)->toJson());
    }

    public function testOfDropsAKeyTheSignatureDoesNotCover(): void
    {
        $withExtraKey = json_encode([...self::event()->toArray(), 'evil' => 'unsigned'], JSON_THROW_ON_ERROR);
        $parsed = Event::tryFromJson($withExtraKey) ?? throw new RuntimeException('fixture did not parse');

        self::assertStringNotContainsString('evil', EncodedEvent::of($parsed)->toJson());
    }

    public function testOfEncodesAPrettyPrintedInputCanonically(): void
    {
        $pretty = json_encode(self::event()->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $parsed = Event::tryFromJson($pretty) ?? throw new RuntimeException('fixture did not parse');

        self::assertSame(self::event()->toJson(), EncodedEvent::of($parsed)->toJson());
    }

    public function testFromOwnStoreKeepsTheBytesItIsGivenWithoutParsingThem(): void
    {
        self::assertSame('{"not":"an event"}', EncodedEvent::fromOwnStore('{"not":"an event"}')->toJson());
    }

    public function testNothingOutsideTheTwoNamedConstructorsCanBuildOne(): void
    {
        self::assertTrue(new ReflectionClass(EncodedEvent::class)->getConstructor()?->isPrivate());
    }

    public function testAFrameCarriesTheBytesVerbatimAfterTheSubscriptionId(): void
    {
        $frame = EncodedEvent::fromOwnStore('{"not":"an event"}')->framedFor(SubscriptionIdMother::from('sub-1'));

        self::assertSame('["EVENT","sub-1",{"not":"an event"}]', $frame);
    }

    public function testAFrameEncodesTheSubscriptionIdAsAJsonString(): void
    {
        $frame = EncodedEvent::fromOwnStore('{}')->framedFor(SubscriptionIdMother::from('a"b\\c/d'));

        self::assertSame(['EVENT', 'a"b\\c/d', []], json_decode($frame, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAFrameParsesAsTheEventMessageForTheSameEvent(): void
    {
        $event = self::event();

        $message = EventMessage::tryFromJson(EncodedEvent::of($event)->framedFor(SubscriptionIdMother::from('sub-1')));

        self::assertTrue($message?->getEvent()->getId()->equals($event->getId()));
    }

    private static function event(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString("line one\u{2028}line two / \"quoted\" \u{e9}"),
            new TagCollection(),
        ));
    }
}
