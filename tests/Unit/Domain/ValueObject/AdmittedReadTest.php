<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\ValueObject\ActingKeys;
use Innis\Nostr\Relay\Domain\ValueObject\AdmittedRead;
use Innis\Nostr\Relay\Domain\ValueObject\ScopedFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdmittedReadTest extends TestCase
{
    private const string READER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @return iterable<string, array{int}>
     */
    public static function giftWrapKinds(): iterable
    {
        yield 'gift wrap' => [EventKind::GIFT_WRAP];
        yield 'ephemeral gift wrap' => [EventKind::EPHEMERAL_GIFT_WRAP];
    }

    public function testTheGrantedFiltersAreReadAsThePolicyGrantedThem(): void
    {
        $filters = self::filters([['kinds' => [1, EventKind::GIFT_WRAP], 'limit' => 10]]);

        $this->assertSame($filters, new AdmittedRead(ScopedFilters::unchanged($filters), self::reader([self::READER]))->getFilters());
    }

    public function testTheReadCarriesTheKeysTheReaderActsAs(): void
    {
        $reader = self::reader([self::READER]);

        $this->assertSame($reader, new AdmittedRead(ScopedFilters::unchanged(self::filters([[]])), $reader)->getReader());
    }

    #[DataProvider('giftWrapKinds')]
    public function testAFilterNamingAGiftWrapKindWithoutNamingTheReaderIsBeyondScope(int $kind): void
    {
        $this->assertTrue(self::read([['kinds' => [$kind]]], [self::READER])->isBeyondScope());
    }

    public function testAFilterNamingAGiftWrapKindForTheReaderIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => [EventKind::GIFT_WRAP]]], [self::READER])->isWhollyBeyondScope());
    }

    #[DataProvider('giftWrapKinds')]
    public function testAGuestsGiftWrapFilterIsWhollyBeyondScope(int $kind): void
    {
        $this->assertTrue(self::read([['kinds' => [$kind]]], [])->isWhollyBeyondScope());
    }

    public function testAFilterNamingAnotherKeysGiftWrapsIsWhollyBeyondScope(): void
    {
        $this->assertTrue(self::read([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::OTHER]]], [self::READER])->isWhollyBeyondScope());
    }

    public function testAFilterNamingTheReaderAndAnotherKeyIsBeyondScope(): void
    {
        $this->assertTrue(self::read([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER, self::OTHER]]], [self::READER])->isBeyondScope());
    }

    public function testAFilterNamingTheReaderAndAnotherKeyIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER, self::OTHER]]], [self::READER])->isWhollyBeyondScope());
    }

    public function testAFilterConfinedToTheReadersKeysIsNotBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => [EventKind::GIFT_WRAP], '#p' => [self::READER]]], [self::READER])->isBeyondScope());
    }

    public function testAGuestsFilterMixingKindsIsBeyondScope(): void
    {
        $this->assertTrue(self::read([['kinds' => [1, EventKind::GIFT_WRAP]]], [])->isBeyondScope());
    }

    public function testAGuestsFilterMixingKindsIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => [1, EventKind::GIFT_WRAP]]], [])->isWhollyBeyondScope());
    }

    public function testAFilterNamingNoGiftWrapKindIsNotBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => [1], '#p' => [self::OTHER]]], [])->isBeyondScope());
    }

    public function testAFilterNamingNoKindIsNotBeyondScope(): void
    {
        $this->assertFalse(self::read([['#p' => [self::OTHER]]], [])->isBeyondScope());
    }

    public function testAGiftWrapFilterBesideOneTheReaderCanMatchIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => [EventKind::GIFT_WRAP]], ['kinds' => [1]]], [])->isWhollyBeyondScope());
    }

    public function testAReadThePolicyNarrowedIsBeyondScope(): void
    {
        $this->assertTrue(new AdmittedRead(ScopedFilters::scoped(self::filters([['kinds' => [1]]]), true), self::reader([]))->isBeyondScope());
    }

    public function testAReadThePolicyNarrowedToNothingIsWhollyBeyondScope(): void
    {
        $this->assertTrue(new AdmittedRead(ScopedFilters::scoped(new FilterCollection([]), true), self::reader([]))->isWhollyBeyondScope());
    }

    public function testAReadThePolicyNarrowedToFiltersThatCanMatchNothingIsWhollyBeyondScope(): void
    {
        $this->assertTrue(new AdmittedRead(ScopedFilters::scoped(self::filters([['kinds' => []]]), true), self::reader([]))->isWhollyBeyondScope());
    }

    public function testAReadThePolicyNarrowedThatCanStillMatchIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(new AdmittedRead(ScopedFilters::scoped(self::filters([['kinds' => []], ['kinds' => [1]]]), true), self::reader([]))->isWhollyBeyondScope());
    }

    public function testAReadWithinScopeThatCanMatchNothingIsNotWhollyBeyondScope(): void
    {
        $this->assertFalse(self::read([['kinds' => []]], [])->isWhollyBeyondScope());
    }

    /**
     * @param list<array<string, mixed>> $filters
     * @param list<string>               $readerKeys
     */
    private static function read(array $filters, array $readerKeys): AdmittedRead
    {
        return new AdmittedRead(ScopedFilters::unchanged(self::filters($filters)), self::reader($readerKeys));
    }

    /**
     * @param list<string> $keys
     */
    private static function reader(array $keys): ActingKeys
    {
        return new ActingKeys(PublicKeyCollection::fromHexValues($keys));
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
}
