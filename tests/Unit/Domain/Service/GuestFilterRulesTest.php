<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Relay\Domain\Service\GuestFilterRules;
use PHPUnit\Framework\TestCase;

final class GuestFilterRulesTest extends TestCase
{
    private const TENANT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testConstrainAuthorsIntersectsRequestedAuthorsWithTenants(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1]);

        $constrained = $rules->constrainAuthorsToTenants(new Filter(authors: [self::TENANT, self::OTHER]));

        self::assertSame([self::TENANT], $constrained->getAuthors());
    }

    public function testConstrainAuthorsDefaultsToTenantsWhenNoAuthorsRequested(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1]);

        $constrained = $rules->constrainAuthorsToTenants(new Filter(kinds: [1]));

        self::assertSame([self::TENANT], $constrained->getAuthors());
    }

    public function testConstrainKindsFillsReadableWhenFilterHasNone(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $constrained = $rules->constrainKindsToReadable(new Filter());

        self::assertTrue($constrained->hasKinds());
        self::assertSame([1, 7], array_map(static fn ($kind) => $kind->toInt(), $constrained->getKinds() ?? []));
    }

    public function testConstrainKindsIntersectsRequestedWithReadable(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $constrained = $rules->constrainKindsToReadable(new Filter(kinds: [1, 4]));

        self::assertSame([1], array_map(static fn ($kind) => $kind->toInt(), $constrained->getKinds() ?? []));
    }

    public function testConstrainKindsEmptyWhenAllRequestedAreUnreadable(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $constrained = $rules->constrainKindsToReadable(new Filter(kinds: [4]));

        self::assertSame([], $constrained->getKinds());
    }

    public function testAuthorsWithinTenants(): void
    {
        $rules = new GuestFilterRules([self::TENANT], []);

        self::assertTrue($rules->authorsWithinTenants(new Filter(authors: [self::TENANT])));
        self::assertTrue($rules->authorsWithinTenants(new Filter(kinds: [1])));
        self::assertFalse($rules->authorsWithinTenants(new Filter(authors: [self::TENANT, self::OTHER])));
    }

    public function testKindsWithinReadable(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        self::assertTrue($rules->kindsWithinReadable(new Filter(kinds: [1, 7])));
        self::assertTrue($rules->kindsWithinReadable(new Filter(authors: [self::TENANT])));
        self::assertFalse($rules->kindsWithinReadable(new Filter(kinds: [1, 4])));
    }

    public function testKindsWithinReadableAllowsEverythingWhenNoReadableKindsConfigured(): void
    {
        $rules = new GuestFilterRules([self::TENANT], []);

        self::assertTrue($rules->kindsWithinReadable(new Filter(kinds: [9999])));
    }

    public function testScopeConstrainsFiltersWithinScope(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $scoped = $rules->scope([new Filter(kinds: [1])], false);

        self::assertFalse($scoped->isBeyondScope());
        self::assertCount(1, $scoped->getFilters());
        self::assertSame([1], array_map(static fn ($kind) => $kind->toInt(), $scoped->getFilters()[0]->getKinds() ?? []));
    }

    public function testScopeFlagsBeyondScopeWhenKindsExceedReadable(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $scoped = $rules->scope([new Filter(kinds: [1, 4])], false);

        self::assertTrue($scoped->isBeyondScope());
        self::assertSame([1], array_map(static fn ($kind) => $kind->toInt(), $scoped->getFilters()[0]->getKinds() ?? []));
    }

    public function testScopeConstrainsAuthorsWhenFromTenantsOnly(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1]);

        $scoped = $rules->scope([new Filter(authors: [self::TENANT, self::OTHER])], true);

        self::assertTrue($scoped->isBeyondScope());
        self::assertSame([self::TENANT], $scoped->getFilters()[0]->getAuthors());
    }

    public function testScopeLeavesAuthorsWhenNotFromTenantsOnly(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1]);

        $scoped = $rules->scope([new Filter(authors: [self::OTHER], kinds: [1])], false);

        self::assertFalse($scoped->isBeyondScope());
        self::assertSame([self::OTHER], $scoped->getFilters()[0]->getAuthors());
    }
}
