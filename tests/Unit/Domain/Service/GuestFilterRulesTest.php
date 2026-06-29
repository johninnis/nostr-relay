<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagFilter;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GuestFilterRulesTest extends TestCase
{
    private const TENANT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testScopeIntersectsRequestedAuthorsWithTenants(): void
    {
        $rules = self::rules([self::TENANT], [1]);

        $scoped = $rules->scope(new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues([self::TENANT, self::OTHER]), kinds: EventKindCollection::fromInts([1]))]), true);

        self::assertSame([self::TENANT], self::authorHexes($scoped->getFilters()->toArray()[0]));
    }

    public function testScopeDefaultsAuthorsToTenantsWhenNoneRequested(): void
    {
        $rules = self::rules([self::TENANT], [1]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1]))]), true);

        self::assertSame([self::TENANT], self::authorHexes($scoped->getFilters()->toArray()[0]));
    }

    public function testScopeFillsReadableKindsWhenFilterHasNone(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        $scoped = $rules->scope(new FilterCollection([new Filter()]), false);

        $filter = $scoped->getFilters()->toArray()[0];
        self::assertTrue($filter->hasKinds());
        self::assertSame([1, 7], $filter->getKinds()?->toInts());
    }

    public function testScopeIntersectsRequestedKindsWithReadable(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1, 4]))]), false);

        self::assertSame([1], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testScopeEmptiesKindsWhenAllRequestedAreUnreadable(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([4]))]), false);

        self::assertSame([], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testScopeNotBeyondScopeWhenAuthorsWithinTenants(): void
    {
        $rules = self::rules([self::TENANT], []);

        self::assertFalse($rules->scope(new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues([self::TENANT]))]), true)->isBeyondScope());
        self::assertFalse($rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1]))]), true)->isBeyondScope());
    }

    public function testScopeBeyondScopeWhenAuthorsExceedTenants(): void
    {
        $rules = self::rules([self::TENANT], []);

        self::assertTrue($rules->scope(new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues([self::TENANT, self::OTHER]))]), true)->isBeyondScope());
    }

    public function testScopeNotBeyondScopeWhenKindsWithinReadable(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        self::assertFalse($rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1, 7]))]), false)->isBeyondScope());
        self::assertFalse($rules->scope(new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues([self::TENANT]))]), false)->isBeyondScope());
    }

    public function testScopeBeyondScopeWhenKindsExceedReadable(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        self::assertTrue($rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1, 4]))]), false)->isBeyondScope());
    }

    public function testScopeNotBeyondScopeWhenNoReadableKindsConfigured(): void
    {
        $rules = self::rules([self::TENANT], []);

        self::assertFalse($rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([9999]))]), false)->isBeyondScope());
    }

    public function testScopeConstrainsFiltersWithinScope(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1]))]), false);

        self::assertFalse($scoped->isBeyondScope());
        self::assertCount(1, $scoped->getFilters());
        self::assertSame([1], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testScopeFlagsBeyondScopeWhenKindsExceedReadable(): void
    {
        $rules = self::rules([self::TENANT], [1, 7]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([1, 4]))]), false);

        self::assertTrue($scoped->isBeyondScope());
        self::assertSame([1], $scoped->getFilters()->toArray()[0]->getKinds()?->toInts());
    }

    public function testScopeConstrainsAuthorsWhenFromTenantsOnly(): void
    {
        $rules = self::rules([self::TENANT], [1]);

        $scoped = $rules->scope(new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues([self::TENANT, self::OTHER]))]), true);

        self::assertTrue($scoped->isBeyondScope());
        self::assertSame([self::TENANT], self::authorHexes($scoped->getFilters()->toArray()[0]));
    }

    public function testScopeLeavesAuthorsWhenNotFromTenantsOnly(): void
    {
        $rules = self::rules([self::TENANT], [1]);

        $scoped = $rules->scope(new FilterCollection([new Filter(authors: PublicKeyCollection::fromHexValues([self::OTHER]), kinds: EventKindCollection::fromInts([1]))]), false);

        self::assertFalse($scoped->isBeyondScope());
        self::assertSame([self::OTHER], self::authorHexes($scoped->getFilters()->toArray()[0]));
    }

    public function testBeyondScopeWhenPTagReferencesTenant(): void
    {
        $rules = self::rules([self::TENANT], [24133]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([24133]), tags: TagFilter::fromValues(['p' => [self::TENANT]]))]), true);

        self::assertTrue($scoped->isBeyondScope());
    }

    public function testNotBeyondScopeWhenPTagReferencesNonTenant(): void
    {
        $rules = self::rules([self::TENANT], [24133]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([24133]), tags: TagFilter::fromValues(['p' => [self::OTHER]]))]), true);

        self::assertFalse($scoped->isBeyondScope());
    }

    public function testPTagToTenantDoesNotTriggerChallengeWhenNotFromTenantsOnly(): void
    {
        $rules = self::rules([self::TENANT], [24133]);

        $scoped = $rules->scope(new FilterCollection([new Filter(kinds: EventKindCollection::fromInts([24133]), tags: TagFilter::fromValues(['p' => [self::TENANT]]))]), false);

        self::assertFalse($scoped->isBeyondScope());
    }

    /**
     * @param list<string> $tenantHexes
     * @param list<int>    $kindInts
     */
    private static function rules(array $tenantHexes, array $kindInts): GuestFilterRules
    {
        return new GuestFilterRules(
            new PublicKeyCollection(array_map(
                static fn (string $hex): PublicKey => PublicKey::fromHex($hex) ?? throw new RuntimeException('Invalid test pubkey'),
                $tenantHexes,
            )),
            new EventKindCollection(array_map(static fn (int $kind): EventKind => EventKind::fromInt($kind), $kindInts)),
        );
    }

    /**
     * @return list<string>
     */
    private static function authorHexes(Filter $filter): array
    {
        return $filter->getAuthors()?->toHexes() ?? [];
    }
}
