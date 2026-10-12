<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Relay\Tests\Support\ActingKeysMother;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ActingKeysTest extends TestCase
{
    private const string CAROL = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

    /**
     * @return iterable<string, array{int}>
     */
    public static function giftWrapKinds(): iterable
    {
        yield 'gift wrap' => [EventKind::GIFT_WRAP];
        yield 'ephemeral gift wrap' => [EventKind::EPHEMERAL_GIFT_WRAP];
    }

    public function testBothGiftWrapKindsAreConfined(): void
    {
        $this->assertSame([EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP], ActingKeysMother::anonymous()->getConfinedKinds()->toInts());
    }

    #[DataProvider('giftWrapKinds')]
    public function testAGiftWrapAddressedToTheReaderIsReadable(int $kind): void
    {
        $this->assertTrue(ActingKeysMother::of(KeyMother::bobPublicKey())->canRead(self::event(KeyMother::alicePublicKey(), $kind, KeyMother::bobPublicKey())));
    }

    #[DataProvider('giftWrapKinds')]
    public function testAGiftWrapAddressedToAnotherKeyIsNotReadable(int $kind): void
    {
        $this->assertFalse(ActingKeysMother::of(KeyMother::alicePublicKey())->canRead(self::event(KeyMother::alicePublicKey(), $kind, KeyMother::bobPublicKey())));
    }

    public function testAReaderWithNoKeysReadsNoGiftWrap(): void
    {
        $this->assertFalse(ActingKeysMother::anonymous()->canRead(EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAGiftWrapAddressedToSeveralKeysIsReadableByAnyOfThem(): void
    {
        $this->assertTrue(ActingKeysMother::of(KeyMother::bobPublicKey())->canRead(self::event(KeyMother::alicePublicKey(), EventKind::GIFT_WRAP, self::carol(), KeyMother::bobPublicKey())));
    }

    public function testAReaderWithSeveralKeysReadsAGiftWrapAddressedToAnyOfThem(): void
    {
        $this->assertTrue(ActingKeysMother::of(KeyMother::alicePublicKey(), self::carol())->canRead(EventMother::giftWrapFor(self::carol())));
    }

    public function testAnEventOfAnotherKindIsReadableByAReaderWithNoKeys(): void
    {
        $this->assertTrue(ActingKeysMother::anonymous()->canRead(self::event(KeyMother::alicePublicKey(), EventKind::TEXT_NOTE, KeyMother::bobPublicKey())));
    }

    public function testAKeyDeletesWhatItSigned(): void
    {
        $this->assertTrue(ActingKeysMother::of(KeyMother::alicePublicKey())->canDelete(self::event(KeyMother::alicePublicKey(), EventKind::TEXT_NOTE)));
    }

    public function testAKeyDeletesAGiftWrapAddressedToIt(): void
    {
        $this->assertTrue(ActingKeysMother::of(KeyMother::bobPublicKey())->canDelete(EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAKeyDeletesAGiftWrapItSigned(): void
    {
        $this->assertTrue(ActingKeysMother::of(KeyMother::alicePublicKey())->canDelete(EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAKeyDoesNotDeleteAGiftWrapAddressedToAnotherKey(): void
    {
        $this->assertFalse(ActingKeysMother::of(self::carol())->canDelete(EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAKeyDoesNotDeleteAnEventOfAnotherKindThatMerelyTagsIt(): void
    {
        $this->assertFalse(ActingKeysMother::of(KeyMother::bobPublicKey())->canDelete(self::event(KeyMother::alicePublicKey(), EventKind::TEXT_NOTE, KeyMother::bobPublicKey())));
    }

    public function testAKeyDoesNotDeleteItsOwnDeletion(): void
    {
        $this->assertFalse(ActingKeysMother::of(KeyMother::alicePublicKey())->canDelete(self::event(KeyMother::alicePublicKey(), EventKind::EVENT_DELETION)));
    }

    private static function event(PublicKey $author, int $kind, PublicKey ...$tagged): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            $author,
            EventKind::fromInt($kind),
            EventContent::fromString('acting keys'),
            new TagCollection(array_map(Tag::pubkey(...), array_values($tagged))),
        ));
    }

    private static function carol(): PublicKey
    {
        return PublicKey::tryFromHex(self::CAROL) ?? throw new RuntimeException('public key did not parse');
    }
}
