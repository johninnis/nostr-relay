<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Relay\Domain\Service\GiftWrapFilterRules;
use Innis\Nostr\Relay\Domain\ValueObject\EventHeader;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use Innis\Nostr\Relay\Tests\Support\EventMother;
use Innis\Nostr\Relay\Tests\Support\KeyMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GiftWrapFilterRulesTest extends TestCase
{
    private const string READER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string EVENT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testAFilterNamingTheGiftWrapKindIsConfinedToTheReadersKeys(): void
    {
        $confined = self::confine([['kinds' => [EventKind::GIFT_WRAP]]], [self::READER]);

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterNamingTheEphemeralGiftWrapKindIsConfinedToTheReadersKeys(): void
    {
        $confined = self::confine([['kinds' => [EventKind::EPHEMERAL_GIFT_WRAP]]], [self::READER]);

        $this->assertSame([['kinds' => [EventKind::EPHEMERAL_GIFT_WRAP], '#p' => [self::READER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAConfinedGiftWrapFilterIsBeyondScope(): void
    {
        $this->assertTrue(self::confine([['kinds' => [EventKind::GIFT_WRAP]]], [self::READER])->isBeyondScope());
    }

    public function testAGuestsGiftWrapFilterIsWhollyBeyondScope(): void
    {
        $this->assertTrue(self::confine([['kinds' => [EventKind::GIFT_WRAP]]], [])->isWhollyBeyondScope());
    }

    public function testAFilterNamingAnotherKeysGiftWrapsIsWhollyBeyondScope(): void
    {
        $this->assertTrue(self::confine([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::OTHER]]], [self::READER])->isWhollyBeyondScope());
    }

    public function testAFilterNamingTheReaderAndAnotherKeyIsConfinedToTheReader(): void
    {
        $confined = self::confine([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER, self::OTHER]]], [self::READER]);

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterAlreadyConfinedToTheReadersKeysIsUnchanged(): void
    {
        $confined = self::confine([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]], [self::READER]);

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterAlreadyConfinedToTheReadersKeysIsNotBeyondScope(): void
    {
        $this->assertFalse(self::confine([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]], [self::READER])->isBeyondScope());
    }

    public function testAFilterMixingKindsKeepsTheOtherKindsForAGuest(): void
    {
        $confined = self::confine([['kinds' => [1, EventKind::GIFT_WRAP]]], []);

        $this->assertSame([['kinds' => [1]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterMixingKindsSplitsTheGiftWrapKindsOffToTheReadersKeys(): void
    {
        $confined = self::confine([['kinds' => [1, EventKind::GIFT_WRAP], 'limit' => 10]], [self::READER]);

        $this->assertSame(
            [['kinds' => [1], 'limit' => 10], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER], 'limit' => 10]],
            $confined->getFilters()->toJsonArray(),
        );
    }

    public function testTheOtherTagFiltersOfAConfinedFilterAreKept(): void
    {
        $confined = self::confine([['kinds' => [EventKind::GIFT_WRAP], '#e' => [self::EVENT]]], [self::READER]);

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP], '#e' => [self::EVENT], '#p' => [self::READER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterNamingNoGiftWrapKindIsUnchanged(): void
    {
        $confined = self::confine([['kinds' => [1], '#p' => [self::OTHER]]], []);

        $this->assertSame([['kinds' => [1], '#p' => [self::OTHER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterNamingNoGiftWrapKindIsNotBeyondScope(): void
    {
        $this->assertFalse(self::confine([['kinds' => [1]]], [])->isBeyondScope());
    }

    public function testAFilterNamingNoKindIsLeftForDeliveryToWithholdFrom(): void
    {
        $confined = self::confine([['#p' => [self::OTHER]]], []);

        $this->assertSame([['#p' => [self::OTHER]]], $confined->getFilters()->toJsonArray());
    }

    public function testAFilterNamingNoKindIsNotBeyondScope(): void
    {
        $this->assertFalse(self::confine([[]], [])->isBeyondScope());
    }

    public function testAReadAlreadyBeyondScopeStaysBeyondScope(): void
    {
        $scoped = ScopedFilters::scoped(self::filters([['kinds' => [1]]]), true);

        $this->assertTrue(new GiftWrapFilterRules()->confine($scoped, new PublicKeyCollection([]))->isBeyondScope());
    }

    #[DataProvider('giftWrapKinds')]
    public function testAGiftWrapIsWithheldFromARequestHoldingAFilterNamingNoKind(int $kind): void
    {
        $this->assertTrue(new GiftWrapFilterRules()->withholds(self::filters([[]]), self::header($kind)));
    }

    public function testAGiftWrapIsWithheldFromARequestNamingAnotherKeyWithoutAKind(): void
    {
        $this->assertTrue(new GiftWrapFilterRules()->withholds(self::filters([['#p' => [self::OTHER]]]), self::header(EventKind::GIFT_WRAP)));
    }

    public function testAGiftWrapIsNotWithheldFromARequestWhoseFiltersAllNameKinds(): void
    {
        $filters = self::filters([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]]);

        $this->assertFalse(new GiftWrapFilterRules()->withholds($filters, self::header(EventKind::GIFT_WRAP)));
    }

    public function testAnEventOfAnotherKindIsNotWithheld(): void
    {
        $this->assertFalse(new GiftWrapFilterRules()->withholds(self::filters([[]]), self::header(1)));
    }

    public function testARequestHoldingAFilterNamingNoKindWithholdsGiftWraps(): void
    {
        $this->assertTrue(new GiftWrapFilterRules()->withholdsGiftWraps(self::filters([['kinds' => [1]], []])));
    }

    public function testARequestWhoseFiltersAllNameKindsWithholdsNoGiftWraps(): void
    {
        $this->assertFalse(new GiftWrapFilterRules()->withholdsGiftWraps(self::filters([['kinds' => [1]]])));
    }

    public function testTheGiftWrapsAFilterNamingNoKindMatchesAreBothGiftWrapKinds(): void
    {
        $giftWraps = new GiftWrapFilterRules()->giftWrapsMatchedBy(self::filters([['#p' => [self::OTHER]]]));

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP, EventKind::EPHEMERAL_GIFT_WRAP], '#p' => [self::OTHER]]], $giftWraps->toJsonArray());
    }

    public function testTheGiftWrapsAFilterNamingKindsMatchesAreItsGiftWrapKinds(): void
    {
        $giftWraps = new GiftWrapFilterRules()->giftWrapsMatchedBy(self::filters([['kinds' => [1, EventKind::GIFT_WRAP]]]));

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP]]], $giftWraps->toJsonArray());
    }

    public function testAFilterNamingNoGiftWrapKindMatchesNoGiftWraps(): void
    {
        $this->assertCount(0, new GiftWrapFilterRules()->giftWrapsMatchedBy(self::filters([['kinds' => [1]]])));
    }

    public function testASplitFilterIsReadAsOneGroup(): void
    {
        $confined = self::confine([['kinds' => [1, EventKind::GIFT_WRAP]]], [self::READER]);

        $this->assertSame([2], array_map(count(...), $confined->getGroups()->toArray()));
    }

    public function testEachRequestedFilterStaysItsOwnGroup(): void
    {
        $confined = self::confine([['kinds' => [EventKind::GIFT_WRAP]], ['kinds' => [1]]], [self::READER]);

        $this->assertSame([1, 1], array_map(count(...), $confined->getGroups()->toArray()));
    }

    public function testAGroupLeftWithNoFilterIsDropped(): void
    {
        $this->assertCount(0, self::confine([['kinds' => [EventKind::GIFT_WRAP]]], [])->getGroups());
    }

    public function testAFilterNamingNoKindDoesNotMatchAGiftWrap(): void
    {
        $this->assertFalse(new GiftWrapFilterRules()->matches(self::filters([[]]), EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAFilterNamingNoKindStillMatchesAnEventOfAnotherKind(): void
    {
        $this->assertTrue(new GiftWrapFilterRules()->matches(self::filters([[]]), self::note()));
    }

    public function testAGiftWrapIsMatchedByAFilterNamingItsKind(): void
    {
        $filters = self::filters([['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]]);

        $this->assertTrue(new GiftWrapFilterRules()->matches($filters, EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAGiftWrapIsMatchedByAFilterNamingItsKindBesideAFilterNamingNone(): void
    {
        $filters = self::filters([[], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::bobPublicKey()->toHex()]]]);

        $this->assertTrue(new GiftWrapFilterRules()->matches($filters, EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testAGiftWrapAddressedElsewhereIsNotMatchedByAFilterConfinedToAnotherKey(): void
    {
        $filters = self::filters([[], ['kinds' => [EventKind::GIFT_WRAP], '#p' => [KeyMother::alicePublicKey()->toHex()]]]);

        $this->assertFalse(new GiftWrapFilterRules()->matches($filters, EventMother::giftWrapFor(KeyMother::bobPublicKey())));
    }

    public function testTheGiftWrapsNamedByARequestAreItsFiltersNamingTheGiftWrapKinds(): void
    {
        $giftWraps = new GiftWrapFilterRules()->giftWrapsNamedBy(self::filters([[], ['kinds' => [1, EventKind::GIFT_WRAP]]]));

        $this->assertSame([['kinds' => [EventKind::GIFT_WRAP]]], $giftWraps->toJsonArray());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function giftWrapKinds(): iterable
    {
        yield 'gift wrap' => [EventKind::GIFT_WRAP];
        yield 'ephemeral gift wrap' => [EventKind::EPHEMERAL_GIFT_WRAP];
    }

    /**
     * @param list<array<string, mixed>> $filters
     * @param list<string>               $readers
     */
    private static function confine(array $filters, array $readers): ScopedFilters
    {
        return new GiftWrapFilterRules()->confine(ScopedFilters::unchanged(self::filters($filters)), PublicKeyCollection::fromHexValues($readers));
    }

    /**
     * @param list<array<string, mixed>> $filters
     */
    private static function filters(array $filters): FilterCollection
    {
        return new FilterCollection(array_map(
            static fn (array $filter): Filter => Filter::tryFromArray($filter) ?? throw new RuntimeException('filter did not parse'),
            $filters,
        ));
    }

    private static function note(): Event
    {
        return EventMother::fromRumour(Rumour::draft(
            KeyMother::alicePublicKey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('hello'),
            new TagCollection([]),
        ));
    }

    private static function header(int $kind): EventHeader
    {
        return new EventHeader(
            EventId::tryFromHex(self::EVENT) ?? throw new RuntimeException('event id did not parse'),
            PublicKey::tryFromHex(self::OTHER) ?? throw new RuntimeException('public key did not parse'),
            EventKind::fromInt($kind),
        );
    }
}
