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
}
