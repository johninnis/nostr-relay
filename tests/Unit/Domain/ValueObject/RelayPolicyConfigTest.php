<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
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

    public function testDefaultsWhenMaxEventSizeIsMalformed(): void
    {
        $config = RelayPolicyConfig::tryFromArray(['max_event_size' => 'huge']);

        $this->assertNotNull($config);
        $this->assertSame(65536, $config->getMaxEventSize());
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
}
