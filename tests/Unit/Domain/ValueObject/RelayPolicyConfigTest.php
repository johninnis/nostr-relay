<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelayPolicyConfigTest extends TestCase
{
    public function testReturnsNullWhenTenantKeyDoesNotParse(): void
    {
        $this->assertNull(RelayPolicyConfig::tryFromArray(['tenants' => ['not-a-valid-key']]));
    }

    public function testReturnsNullWhenTenantIsNotAString(): void
    {
        $this->assertNull(RelayPolicyConfig::tryFromArray(['tenants' => [123]]));
    }

    public function testParsesValidHexTenantKey(): void
    {
        $this->assertNotNull(RelayPolicyConfig::tryFromArray(['tenants' => [str_repeat('a', 64)]]));
    }

    public function testAValidHexTenantKeyIsTheOneTenant(): void
    {
        $this->assertSame(1, RelayPolicyConfig::tryFromArray(['tenants' => [str_repeat('a', 64)]])?->getTenants()->count());
    }

    public function testAGuestSectionWithNoReadSectionParses(): void
    {
        $this->assertNotNull(RelayPolicyConfig::tryFromArray(['guest' => ['write' => [['kinds' => [1]]]]]));
    }

    public function testReadableKindsAreUnrestrictedOnlyWhenThereIsNoReadSection(): void
    {
        $this->assertNull(RelayPolicyConfig::tryFromArray(['guest' => ['write' => [['kinds' => [1]]]]])?->getGuest()->getReadableKinds());
    }

    public function testAReadSectionListingNoKindsReadsNothing(): void
    {
        $this->assertSame([], RelayPolicyConfig::tryFromArray(['guest' => ['read' => [['kinds' => []]]]])?->getGuest()->getReadableKinds()?->toInts());
    }

    public function testAnEmptyReadSectionReadsNothing(): void
    {
        $this->assertSame([], RelayPolicyConfig::tryFromArray(['guest' => ['read' => []]])?->getGuest()->getReadableKinds()?->toInts());
    }

    public function testReadableKindsAreTheKindsTheReadRulesList(): void
    {
        $this->assertSame([1, 7], RelayPolicyConfig::tryFromArray(['guest' => ['read' => [['kinds' => [1]], ['kinds' => [7]]]]])?->getGuest()->getReadableKinds()?->toInts());
    }

    public function testReadRulesListingOnlyMalformedKindsReadNothing(): void
    {
        $this->assertSame([], RelayPolicyConfig::tryFromArray(['guest' => ['read' => [['kinds' => ['one']]]]])?->getGuest()->getReadableKinds()?->toInts());
    }

    #[DataProvider('unusableQueryLimits')]
    public function testAQueryLimitTheRelayCannotApplyIsRefusedRatherThanThrown(int $limit): void
    {
        $this->assertNull(RelayPolicyConfig::tryFromArray(['max_query_limit' => $limit]));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unusableQueryLimits(): iterable
    {
        yield 'zero serves nothing' => [0];
        yield 'negative' => [-5];
    }

    public function testAQueryLimitAboveFiveThousandIsTheRelaysToChoose(): void
    {
        $this->assertNotNull(RelayPolicyConfig::tryFromArray(['max_query_limit' => 10_000]));
    }

    #[DataProvider('unusableFilterValueLimits')]
    public function testAFilterValueLimitTheRelayCannotApplyIsRefusedRatherThanThrown(int $limit): void
    {
        $this->assertNull(RelayPolicyConfig::tryFromArray(['max_filter_values' => $limit]));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unusableFilterValueLimits(): iterable
    {
        yield 'zero serves nothing' => [0];
        yield 'negative' => [-5];
    }

    public function testTheFilterValueLimitIsTaken(): void
    {
        $limits = RelayPolicyConfig::tryFromArray(['max_filter_values' => 1])?->getSubscriptionLimits();

        $this->assertNotNull($limits?->refuseOversizedFilters(new FilterCollection([Filter::tryFromArray(['kinds' => [1, 2]]) ?? self::fail('filter did not parse')])));
    }
}
