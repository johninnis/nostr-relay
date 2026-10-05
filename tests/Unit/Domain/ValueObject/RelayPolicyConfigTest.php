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
        $config = RelayPolicyConfig::tryFromArray(['tenants' => [str_repeat('a', 64)]]);

        $this->assertNotNull($config);
        $this->assertCount(1, $config->getTenants());
    }

    public function testReadableKindsAreUnrestrictedOnlyWhenThereIsNoReadSection(): void
    {
        $config = RelayPolicyConfig::tryFromArray(['guest' => ['write' => [['kinds' => [1]]]]]);

        $this->assertNotNull($config);
        $this->assertNull($config->getGuest()->getReadableKinds());
    }

    public function testAReadSectionListingNoKindsReadsNothing(): void
    {
        $config = RelayPolicyConfig::tryFromArray(['guest' => ['read' => [['kinds' => []]]]]);

        $this->assertNotNull($config);
        $this->assertSame([], $config->getGuest()->getReadableKinds()?->toInts());
    }

    public function testAnEmptyReadSectionReadsNothing(): void
    {
        $config = RelayPolicyConfig::tryFromArray(['guest' => ['read' => []]]);

        $this->assertNotNull($config);
        $this->assertSame([], $config->getGuest()->getReadableKinds()?->toInts());
    }

    public function testReadableKindsAreTheKindsTheReadRulesList(): void
    {
        $config = RelayPolicyConfig::tryFromArray(['guest' => ['read' => [['kinds' => [1]], ['kinds' => [7]]]]]);

        $this->assertNotNull($config);
        $this->assertSame([1, 7], $config->getGuest()->getReadableKinds()?->toInts());
    }

    public function testReadRulesListingOnlyMalformedKindsReadNothing(): void
    {
        $config = RelayPolicyConfig::tryFromArray(['guest' => ['read' => [['kinds' => ['one']]]]]);

        $this->assertNotNull($config);
        $this->assertSame([], $config->getGuest()->getReadableKinds()?->toInts());
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
