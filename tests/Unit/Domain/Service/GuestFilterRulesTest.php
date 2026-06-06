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

    public function testInjectReadableKindsAddsKindsWhenFilterHasNone(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $constrained = $rules->injectReadableKinds(new Filter());

        self::assertTrue($constrained->hasKinds());
        self::assertSame([1, 7], array_map(static fn ($kind) => $kind->toInt(), $constrained->getKinds() ?? []));
    }

    public function testInjectReadableKindsLeavesExplicitKindsUntouched(): void
    {
        $rules = new GuestFilterRules([self::TENANT], [1, 7]);

        $constrained = $rules->injectReadableKinds(new Filter(kinds: [1]));

        self::assertSame([1], array_map(static fn ($kind) => $kind->toInt(), $constrained->getKinds() ?? []));
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
}
